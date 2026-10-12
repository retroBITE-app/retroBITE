/*
 * Random access into a disc image that may be block-compressed.
 *
 * CSO (v1 and v2) and ZSO are the formats OPL and PSP loaders read: the disc
 * cut into fixed blocks, each deflated (CSO), LZ4'd (ZSO, and CSO v2 when
 * flagged) or stored as is, behind an index of where each block starts. A
 * plain image opens too and reads straight through, so a caller never has to
 * know which it was given.
 *
 * Built twice in the rahasher stage of Dockerfile.web: into RAHasher, as the
 * CD reader HashCISO.cpp registers, and as the discread CLI the per-console
 * toolbox scripts in app/Scripts read compressed images through.
 */
#ifndef RETROBITE_CISO_H
#define RETROBITE_CISO_H

#include <stddef.h>
#include <stdint.h>

#ifdef __cplusplus
extern "C" {
#endif

typedef struct ciso ciso_t;

/* Whether the file at path starts with CSO or ZSO magic. */
int ciso_is_compressed(const char* path);

/* Opens a plain or compressed image; NULL when it cannot be read or its header is not believable. */
ciso_t* ciso_open(const char* path);

/* The uncompressed size of the disc in bytes. */
uint64_t ciso_size(const ciso_t* image);

/* Copies up to len bytes of the uncompressed disc from offset; fewer only at its end or on a damaged block. */
size_t ciso_read(ciso_t* image, uint64_t offset, void* buffer, size_t len);

void ciso_close(ciso_t* image);

#ifdef __cplusplus
}
#endif

#endif
