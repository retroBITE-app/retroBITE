/*
 * CSO v1/v2 and ZSO, read a block at a time.
 *
 * The header is 24 bytes: magic, header size, the uncompressed size as a
 * uint64, the block size, a version byte and the index shift. The index
 * follows, one uint32 per block plus one closing entry; an entry's low 31
 * bits, shifted left by the index shift, are where its block starts, and the
 * next entry says where it ends. The top bit means "stored" for CSO v1 and
 * ZSO, and "LZ4" for CSO v2, where a block as long as the block size is the
 * stored one. Blocks are padded to the index shift's alignment, so a block's
 * extent can be longer than its compressed data: LZ4 is decoded with the
 * partial decoder for exactly that reason, and inflate stops at the end of its
 * stream on its own.
 */
#include "ciso.h"

#include <lz4.h>
#include <zlib.h>

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#define CISO_HEADER 24

/* A block never needs to be larger than this, and a header that says otherwise is not a CSO. */
#define CISO_MAX_BLOCK (1u << 24)

/* 64 GiB: larger than any disc these formats hold, small enough that the index stays in memory. */
#define CISO_MAX_TOTAL (64ull << 30)

enum ciso_format { CISO_PLAIN, CISO_CSO1, CISO_CSO2, CISO_ZSO };

struct ciso
{
  FILE* file;
  enum ciso_format format;
  uint64_t total;
  uint32_t block_size;
  uint8_t shift;
  uint32_t blocks;
  uint32_t* index;
  uint8_t* packed;
  uint8_t* block;
  uint32_t cached;
  size_t cached_len;
};

static uint32_t le32(const uint8_t* p)
{
  return (uint32_t)p[0] | ((uint32_t)p[1] << 8) | ((uint32_t)p[2] << 16) | ((uint32_t)p[3] << 24);
}

static uint64_t le64(const uint8_t* p)
{
  return (uint64_t)le32(p) | ((uint64_t)le32(p + 4) << 32);
}

static enum ciso_format sniff(const uint8_t* header, size_t len)
{
  if (len < 4)
    return CISO_PLAIN;

  if (memcmp(header, "ZISO", 4) == 0)
    return CISO_ZSO;

  if (memcmp(header, "CISO", 4) == 0)
    return (len >= CISO_HEADER && header[20] >= 2) ? CISO_CSO2 : CISO_CSO1;

  return CISO_PLAIN;
}

int ciso_is_compressed(const char* path)
{
  uint8_t header[CISO_HEADER];
  size_t len;
  FILE* file = fopen(path, "rb");

  if (!file)
    return 0;

  len = fread(header, 1, sizeof(header), file);
  fclose(file);

  return sniff(header, len) != CISO_PLAIN;
}

static int open_plain(ciso_t* image)
{
  off_t end;

  if (fseeko(image->file, 0, SEEK_END) != 0)
    return 0;

  end = ftello(image->file);

  if (end < 0)
    return 0;

  image->total = (uint64_t)end;

  return 1;
}

static int open_compressed(ciso_t* image, const uint8_t* header)
{
  uint64_t blocks;
  uint8_t* raw;
  size_t entries, i;

  image->total = le64(header + 8);
  image->block_size = le32(header + 16);
  image->shift = header[21];

  if (image->block_size < 2048 || image->block_size > CISO_MAX_BLOCK || (image->block_size & (image->block_size - 1)) != 0)
    return 0;

  if (image->total == 0 || image->total > CISO_MAX_TOTAL || image->shift > 31)
    return 0;

  blocks = (image->total + image->block_size - 1) / image->block_size;
  image->blocks = (uint32_t)blocks;
  entries = (size_t)blocks + 1;

  raw = (uint8_t*)malloc(entries * 4);
  image->index = (uint32_t*)malloc(entries * sizeof(uint32_t));
  /* The extent of a block is its data plus alignment padding of at most 2^shift - 1 bytes. */
  image->packed = (uint8_t*)malloc(image->block_size + ((size_t)1 << image->shift) + 64);
  image->block = (uint8_t*)malloc(image->block_size);

  if (!raw || !image->index || !image->packed || !image->block)
  {
    free(raw);
    return 0;
  }

  if (fseeko(image->file, CISO_HEADER, SEEK_SET) != 0 || fread(raw, 4, entries, image->file) != entries)
  {
    free(raw);
    return 0;
  }

  for (i = 0; i < entries; i++)
    image->index[i] = le32(raw + i * 4);

  free(raw);

  return 1;
}

ciso_t* ciso_open(const char* path)
{
  uint8_t header[CISO_HEADER];
  size_t len;
  int ok;
  ciso_t* image = (ciso_t*)calloc(1, sizeof(ciso_t));

  if (!image)
    return NULL;

  image->cached = UINT32_MAX;
  image->file = fopen(path, "rb");

  if (!image->file)
  {
    free(image);
    return NULL;
  }

  len = fread(header, 1, sizeof(header), image->file);
  image->format = sniff(header, len);

  if (image->format == CISO_PLAIN)
    ok = open_plain(image);
  else
    ok = len == CISO_HEADER && open_compressed(image, header);

  if (!ok)
  {
    ciso_close(image);
    return NULL;
  }

  return image;
}

uint64_t ciso_size(const ciso_t* image)
{
  return image->total;
}

/* Decodes one block into the cache; 0 when it is damaged. */
static int load_block(ciso_t* image, uint32_t n)
{
  uint32_t entry = image->index[n];
  uint32_t next = image->index[n + 1];
  uint64_t start = (uint64_t)(entry & 0x7FFFFFFFu) << image->shift;
  uint64_t end = (uint64_t)(next & 0x7FFFFFFFu) << image->shift;
  uint64_t remaining = image->total - (uint64_t)n * image->block_size;
  size_t expected = remaining < image->block_size ? (size_t)remaining : image->block_size;
  size_t extent, got;
  int flagged = (entry & 0x80000000u) != 0;
  int stored, lz4;

  if (n == image->cached)
    return 1;

  image->cached = UINT32_MAX;

  if (end < start)
    return 0;

  extent = (size_t)(end - start);

  if (image->format == CISO_CSO2)
  {
    stored = extent >= image->block_size;
    lz4 = flagged;
  }
  else
  {
    stored = flagged;
    lz4 = image->format == CISO_ZSO;
  }

  if (fseeko(image->file, (off_t)start, SEEK_SET) != 0)
    return 0;

  if (stored)
  {
    if (fread(image->block, 1, expected, image->file) != expected)
      return 0;
  }
  else
  {
    if (extent > image->block_size + ((size_t)1 << image->shift) + 64 || fread(image->packed, 1, extent, image->file) != extent)
      return 0;

    if (lz4)
    {
      int out = LZ4_decompress_safe_partial((const char*)image->packed, (char*)image->block, (int)extent, (int)expected, (int)image->block_size);

      if (out < 0 || (size_t)out < expected)
        return 0;
    }
    else
    {
      z_stream stream;
      int status;

      memset(&stream, 0, sizeof(stream));

      if (inflateInit2(&stream, -15) != Z_OK)
        return 0;

      stream.next_in = image->packed;
      stream.avail_in = (uInt)extent;
      stream.next_out = image->block;
      stream.avail_out = (uInt)image->block_size;
      status = inflate(&stream, Z_FINISH);
      got = image->block_size - stream.avail_out;
      inflateEnd(&stream);

      if ((status != Z_STREAM_END && status != Z_BUF_ERROR) || got < expected)
        return 0;
    }
  }

  image->cached = n;
  image->cached_len = expected;

  return 1;
}

size_t ciso_read(ciso_t* image, uint64_t offset, void* buffer, size_t len)
{
  uint8_t* out = (uint8_t*)buffer;
  size_t done = 0;

  if (offset >= image->total)
    return 0;

  if (len > image->total - offset)
    len = (size_t)(image->total - offset);

  if (image->format == CISO_PLAIN)
  {
    if (fseeko(image->file, (off_t)offset, SEEK_SET) != 0)
      return 0;

    return fread(buffer, 1, len, image->file);
  }

  while (done < len)
  {
    uint64_t at = offset + done;
    uint32_t n = (uint32_t)(at / image->block_size);
    size_t within = (size_t)(at % image->block_size);
    size_t take;

    if (n >= image->blocks || !load_block(image, n) || within >= image->cached_len)
      break;

    take = image->cached_len - within;

    if (take > len - done)
      take = len - done;

    memcpy(out + done, image->block + within, take);
    done += take;
  }

  return done;
}

void ciso_close(ciso_t* image)
{
  if (!image)
    return;

  if (image->file)
    fclose(image->file);

  free(image->index);
  free(image->packed);
  free(image->block);
  free(image);
}
