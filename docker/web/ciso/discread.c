/*
 * discread <image> <offset> [length]
 *
 * Writes the uncompressed bytes of a disc image, from offset for length bytes
 * or to its end, to stdout. CSO, ZSO and plain images alike, so a toolbox
 * script reads a sector the same way whatever the file is: maxcso can only
 * decompress a whole image to a file, and a 4 GB temporary to read one
 * SYSTEM.CNF is not a price worth paying.
 *
 * Exit 2 for a bad argument or an image that cannot be opened, 1 when a block
 * would not decode; 0 otherwise, including a read that ran into the end.
 */
#include "ciso.h"

#include <errno.h>
#include <stdio.h>
#include <stdlib.h>

#define CHUNK (256 * 1024)

static int number(const char* text, uint64_t* out)
{
  char* end;

  errno = 0;
  *out = strtoull(text, &end, 10);

  return errno == 0 && end != text && *end == '\0' && text[0] != '-';
}

int main(int argc, char** argv)
{
  static unsigned char buffer[CHUNK];
  uint64_t offset, length, remaining;
  ciso_t* image;

  if (argc < 3 || argc > 4 || !number(argv[2], &offset) || (argc == 4 && !number(argv[3], &length)))
  {
    fprintf(stderr, "Usage: discread <image> <offset> [length]\n");
    return 2;
  }

  image = ciso_open(argv[1]);

  if (!image)
  {
    fprintf(stderr, "Cannot open disc image: %s\n", argv[1]);
    return 2;
  }

  if (offset >= ciso_size(image))
    remaining = 0;
  else if (argc == 4 && length < ciso_size(image) - offset)
    remaining = length;
  else
    remaining = ciso_size(image) - offset;

  while (remaining > 0)
  {
    size_t want = remaining < CHUNK ? (size_t)remaining : CHUNK;
    size_t got = ciso_read(image, offset, buffer, want);

    if (got > 0 && fwrite(buffer, 1, got, stdout) != got)
      break;

    if (got < want)
    {
      ciso_close(image);
      fprintf(stderr, "Damaged block near byte %llu\n", (unsigned long long)(offset + got));
      return 1;
    }

    offset += got;
    remaining -= got;
  }

  ciso_close(image);

  return 0;
}
