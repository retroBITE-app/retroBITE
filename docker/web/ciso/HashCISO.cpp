/*
 * An rcheevos CD reader for CSO and ZSO, for RAHasher.
 *
 * Copied into RALibretro's src/ by the rahasher stage of Dockerfile.web and
 * registered in place of the default reader by patches/rahasher-ciso.patch.
 * Every file is sniffed: CSO/ZSO magic opens through ciso.c as one track of
 * data sectors, and anything else is handed to rcheevos' own reader untouched,
 * so a cue sheet or an ISO hashes exactly as it did before. By magic rather
 * than extension, because a CSO renamed to .iso is common on these drives.
 *
 * The hashing itself stays rcheevos': this only supplies sectors.
 */
#include <rc_hash.h>
#include <rcheevos/src/rhash/rc_hash_internal.h>

#include <stdlib.h>
#include <string.h>

#include "ciso.h"

static struct rc_hash_cdreader default_reader;

typedef struct ciso_track_t
{
  ciso_t* image;            /* set for a compressed image */
  void* inner;              /* rcheevos' own track handle otherwise */
  uint32_t sector_size;     /* bytes per stored sector: 2048, or 2352 for a raw dump */
  uint32_t header_size;     /* bytes before the data in each stored sector */
} ciso_track_t;

/* Which sector layout the image holds, from where sector 16 keeps its CD001 tag. */
static void ciso_detect_layout(ciso_track_t* track)
{
  uint8_t buffer[32];

  track->sector_size = 2048;
  track->header_size = 0;

  if (ciso_read(track->image, 16 * 2048, buffer, sizeof(buffer)) == sizeof(buffer) && memcmp(&buffer[1], "CD001", 5) == 0)
    return;

  if (ciso_read(track->image, 16 * 2352, buffer, sizeof(buffer)) != sizeof(buffer))
    return;

  if (memcmp(&buffer[25], "CD001", 5) == 0)
  {
    track->sector_size = 2352;
    track->header_size = 24;
  }
  else if (memcmp(&buffer[17], "CD001", 5) == 0)
  {
    track->sector_size = 2352;
    track->header_size = 16;
  }
}

static void* ciso_open_track(const char* path, uint32_t track, const rc_hash_iterator_t* iterator)
{
  ciso_track_t* handle;

  if (!ciso_is_compressed(path))
  {
    void* inner = default_reader.open_track_iterator
      ? default_reader.open_track_iterator(path, track, iterator)
      : default_reader.open_track(path, track);

    if (!inner)
      return NULL;

    handle = (ciso_track_t*)calloc(1, sizeof(ciso_track_t));

    if (!handle)
    {
      default_reader.close_track(inner);
      return NULL;
    }

    handle->inner = inner;
    return handle;
  }

  /* A CSO or ZSO is one data track; there is no second session or audio to find. */
  if (track != 1 && track != RC_HASH_CDTRACK_FIRST_DATA && track != RC_HASH_CDTRACK_LAST && track != RC_HASH_CDTRACK_LARGEST)
    return NULL;

  handle = (ciso_track_t*)calloc(1, sizeof(ciso_track_t));

  if (!handle)
    return NULL;

  handle->image = ciso_open(path);

  if (!handle->image)
  {
    free(handle);
    rc_hash_iterator_error(iterator, "Could not open compressed image");
    return NULL;
  }

  ciso_detect_layout(handle);
  rc_hash_iterator_verbose(iterator, "Reading compressed image");

  return handle;
}

static size_t ciso_read_sector(void* track_handle, uint32_t sector, void* buffer, size_t requested_bytes)
{
  ciso_track_t* track = (ciso_track_t*)track_handle;
  uint8_t* out = (uint8_t*)buffer;
  size_t done = 0;

  if (track->inner)
    return default_reader.read_sector(track->inner, sector, buffer, requested_bytes);

  if (track->sector_size == 2048)
    return ciso_read(track->image, (uint64_t)sector * 2048, buffer, requested_bytes);

  /* A raw dump: 2048 bytes of data per sector, between its header and its error correction. */
  while (done < requested_bytes)
  {
    size_t take = requested_bytes - done < 2048 ? requested_bytes - done : 2048;
    uint64_t at = (uint64_t)sector * track->sector_size + track->header_size;
    size_t got = ciso_read(track->image, at, out + done, take);

    done += got;

    if (got < take)
      break;

    sector++;
  }

  return done;
}

static void ciso_close_track(void* track_handle)
{
  ciso_track_t* track = (ciso_track_t*)track_handle;

  if (!track)
    return;

  if (track->inner)
    default_reader.close_track(track->inner);

  ciso_close(track->image);
  free(track);
}

static uint32_t ciso_first_track_sector(void* track_handle)
{
  ciso_track_t* track = (ciso_track_t*)track_handle;

  return track->inner ? default_reader.first_track_sector(track->inner) : 0;
}

void rc_hash_init_ciso_cdreader()
{
  struct rc_hash_cdreader cdreader;

  rc_hash_get_default_cdreader(&default_reader);

  memset(&cdreader, 0, sizeof(cdreader));
  cdreader.open_track_iterator = ciso_open_track;
  cdreader.read_sector = ciso_read_sector;
  cdreader.close_track = ciso_close_track;
  cdreader.first_track_sector = ciso_first_track_sector;
  rc_hash_init_custom_cdreader(&cdreader);
}
