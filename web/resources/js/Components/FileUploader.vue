<script setup lang="ts">
import { computed, ref } from 'vue'
import { PhX } from '@phosphor-icons/vue'
import AlertBox from '@/Components/UI/AlertBox.vue'
import BaseButton from '@/Components/UI/BaseButton.vue'
import BaseModal from '@/Components/UI/BaseModal.vue'
import OptionList from '@/Components/UI/OptionList.vue'
import { useModal } from '@/Composables/useModal'
import { formatSize } from '@/Helpers/format'
import { apiHeaders } from '@/Helpers/http'
import { uuidV4 } from '@/Helpers/uuid'
import { route } from '@/routes'
import type { SelectOption } from '@/Types/api'

/** Must stay under nginx's client_max_body_size, which caps one chunk. */
const CHUNK_SIZE = 50 * 1024 * 1024

const props = defineProps<{
  consoleKey: string
  consoleName: string
  acceptedExtensions: string[]
  uploadDirs: SelectOption[]
}>()

const emit = defineEmits<{ done: [] }>()

const isDragging = ref(false)
const selectedFiles = ref<File[]>([])
const selectedDir = ref(props.uploadDirs[0]?.value ?? '')
const uploading = ref(false)
const progress = ref(0)
const statusText = ref('')
const error = ref<string | null>(null)
const fileInput = ref<HTMLInputElement>()

const modal = useModal(uploading, () => {
  selectedFiles.value = []
  selectedDir.value = props.uploadDirs[0]?.value ?? ''
  progress.value = 0
  error.value = null
})

const totalSize = computed(() => selectedFiles.value.reduce((sum, file) => sum + file.size, 0))

/**
 * Highlight the drop zone while a drag is over it.
 */
function onDragOver(event: DragEvent): void {
  event.preventDefault()
  isDragging.value = true
}

/**
 * Drop the drag highlight.
 */
function onDragLeave(): void {
  isDragging.value = false
}

/**
 * Queue whatever was dropped.
 */
function onDrop(event: DragEvent): void {
  event.preventDefault()
  isDragging.value = false

  if (event.dataTransfer?.files) {
    addFiles(event.dataTransfer.files)
  }
}

/**
 * Queue the picked files, then clear the input so the same file can be picked again.
 */
function onFileInput(event: Event): void {
  const files = (event.target as HTMLInputElement).files

  if (files) {
    addFiles(files)
  }

  if (fileInput.value) {
    fileInput.value.value = ''
  }
}

/**
 * Queue files this console accepts, reporting how many were skipped.
 */
function addFiles(fileList: FileList): void {
  error.value = null
  let rejected = 0

  for (const file of Array.from(fileList)) {
    const extension = file.name.split('.').pop()?.toLowerCase() ?? ''

    if (!props.acceptedExtensions.includes(extension)) {
      rejected++
      continue
    }

    const already = selectedFiles.value.some(
      (queued) => queued.name === file.name && queued.size === file.size,
    )

    if (!already) {
      selectedFiles.value.push(file)
    }
  }

  if (rejected > 0) {
    error.value = `Skipped ${rejected} file${rejected > 1 ? 's' : ''} with unsupported extensions`
  }
}

/**
 * Drop one file from the queue.
 */
function removeFile(index: number): void {
  selectedFiles.value.splice(index, 1)
}

/**
 * Upload every queued file, chunk by chunk, reporting overall byte progress.
 */
async function startUpload(): Promise<void> {
  if (selectedFiles.value.length === 0) {
    return
  }

  uploading.value = true
  progress.value = 0
  error.value = null

  const files = [...selectedFiles.value]
  const totalBytes = files.reduce((sum, file) => sum + file.size, 0)
  const failures: string[] = []
  let bytesDone = 0

  for (const [index, file] of files.entries()) {
    statusText.value =
      files.length > 1 ? `File ${index + 1} / ${files.length}: ${file.name}` : file.name

    try {
      await uploadFile(file, bytesDone, totalBytes)
    } catch (e: unknown) {
      failures.push(`${file.name}: ${e instanceof Error ? e.message : 'failed'}`)
    }

    bytesDone += file.size
  }

  progress.value = 100
  uploading.value = false

  if (failures.length > 0) {
    error.value = failures.join(' · ')

    return
  }

  modal.open.value = false
  emit('done')
}

/**
 * Send one file as a sequence of chunks.
 */
async function uploadFile(file: File, bytesBefore: number, totalBytes: number): Promise<void> {
  const uploadId = uuidV4()
  const totalChunks = Math.ceil(file.size / CHUNK_SIZE)

  for (let index = 0; index < totalChunks; index++) {
    const chunk = file.slice(index * CHUNK_SIZE, (index + 1) * CHUNK_SIZE)
    const bytesBeforeChunk = bytesBefore + index * CHUNK_SIZE

    await sendChunk({
      uploadId,
      filename: file.name,
      fileSize: file.size,
      chunkIndex: index,
      totalChunks,
      chunk,
      onProgress: (percent) => {
        const chunkBytes = Math.round((chunk.size * percent) / 100)
        progress.value = Math.round(((bytesBeforeChunk + chunkBytes) / totalBytes) * 100)
      },
    })
  }
}

type ChunkRequest = {
  uploadId: string
  filename: string
  fileSize: number
  chunkIndex: number
  totalChunks: number
  chunk: Blob
  onProgress: (percent: number) => void
}

/**
 * POST one chunk. Uses XHR rather than fetch because only XHR reports upload
 * progress.
 */
function sendChunk(request: ChunkRequest): Promise<void> {
  return new Promise((resolve, reject) => {
    const form = new FormData()
    form.append('upload_id', request.uploadId)
    form.append('filename', request.filename)
    form.append('file_size', String(request.fileSize))
    form.append('subfolder', selectedDir.value)
    form.append('chunk_index', String(request.chunkIndex))
    form.append('total_chunks', String(request.totalChunks))
    form.append('chunk', request.chunk, request.filename)

    const xhr = new XMLHttpRequest()
    xhr.open('POST', route('console.uploadChunk', { console: props.consoleKey }))

    for (const [name, value] of Object.entries(apiHeaders())) {
      xhr.setRequestHeader(name, value)
    }

    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable) {
        request.onProgress(Math.round((event.loaded / event.total) * 100))
      }
    }

    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve()

        return
      }

      reject(new Error(readError(xhr)))
    }

    xhr.onerror = () => reject(new Error('Network error'))
    xhr.send(form)
  })
}

/**
 * The server's error message, or the bare status when the body is not JSON.
 */
function readError(xhr: XMLHttpRequest): string {
  try {
    const body = JSON.parse(xhr.responseText) as { error?: string }

    return body.error ?? `HTTP ${xhr.status}`
  } catch {
    return `HTTP ${xhr.status}`
  }
}
</script>

<template>
  <BaseButton variant="secondary" @click="modal.show">Upload file</BaseButton>

  <BaseModal
    :open="modal.open.value"
    :title="`Upload to ${consoleName}`"
    :busy="uploading"
    size="lg"
    @close="modal.hide"
  >
    <div class="space-y-5">
      <div>
        <div
          :class="[
            isDragging
              ? 'border-accent-tint bg-accent-tint/8'
              : 'border-line-input hover:border-line-bright',
            selectedFiles.length ? 'py-4' : 'py-10',
          ]"
          class="cursor-pointer rounded-lg border-2 border-dashed text-center transition-all"
          @dragover="onDragOver"
          @dragleave="onDragLeave"
          @drop="onDrop"
          @click="fileInput?.click()"
        >
          <p class="text-sm text-fg-soft">
            {{
              selectedFiles.length ? 'Drop more files or click to add' : 'Drag & drop files here'
            }}
          </p>
          <p v-if="!selectedFiles.length" class="mt-1 text-sm text-fg-faint">or click to browse</p>
        </div>

        <input ref="fileInput" type="file" multiple class="hidden" @change="onFileInput" />

        <div class="mt-3 flex flex-wrap items-center gap-1.5">
          <span class="text-sm text-fg-dim">Accepted:</span>
          <span
            v-for="extension in acceptedExtensions"
            :key="extension"
            class="rounded border border-line-strong bg-hover px-1.5 py-0.5 font-mono text-xs text-fg-muted"
          >
            .{{ extension }}
          </span>
        </div>
      </div>

      <div v-if="selectedFiles.length">
        <div class="mb-2 flex items-center justify-between">
          <p class="text-sm text-fg-muted">
            {{ selectedFiles.length }} file{{ selectedFiles.length > 1 ? 's' : '' }}
            <span class="ml-1 font-mono text-xs text-fg-faint"
              >{{ formatSize(totalSize) }} total</span
            >
          </p>
          <button
            type="button"
            :disabled="uploading"
            class="cursor-pointer text-sm text-fg-faint transition-colors hover:text-fg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep disabled:opacity-40"
            @click="selectedFiles = []"
          >
            Clear all
          </button>
        </div>

        <div class="max-h-40 space-y-1 overflow-y-auto pr-1">
          <div
            v-for="(file, index) in selectedFiles"
            :key="file.name + file.size"
            class="flex items-center justify-between rounded-md border border-line-strong bg-surface px-3 py-2"
          >
            <span class="mr-2 min-w-0 truncate text-sm text-fg-soft">{{ file.name }}</span>
            <div class="flex shrink-0 items-center gap-2">
              <span class="font-mono text-xs text-fg-dim">{{ formatSize(file.size) }}</span>
              <button
                type="button"
                aria-label="Remove file"
                :disabled="uploading"
                class="cursor-pointer leading-none text-fg-faint transition-colors hover:text-danger focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep disabled:opacity-40"
                @click="removeFile(index)"
              >
                <PhX :size="12" />
              </button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="uploadDirs.length > 1">
        <p class="mb-2 kicker text-fg-faint">Upload to</p>
        <OptionList
          v-model="selectedDir"
          :options="uploadDirs"
          :disabled="uploading"
          @update:model-value="selectedDir = $event"
        />
      </div>

      <div v-if="uploading" class="space-y-1.5">
        <div class="flex justify-between font-mono text-xs text-fg-muted">
          <span class="mr-2 truncate">{{ statusText }}</span>
          <span class="shrink-0">{{ progress }}%</span>
        </div>
        <div class="h-1.5 overflow-hidden rounded-full bg-raised">
          <div
            class="h-full rounded-full bg-accent-deep transition-all duration-150"
            :style="{ width: progress + '%' }"
          />
        </div>
      </div>

      <AlertBox v-if="error" size="sm">{{ error }}</AlertBox>
    </div>

    <template #footer>
      <BaseButton variant="ghost" :disabled="uploading" @click="modal.hide">Cancel</BaseButton>
      <BaseButton
        :disabled="!selectedFiles.length"
        :busy="uploading"
        busy-label="Uploading…"
        @click="startUpload"
      >
        {{ selectedFiles.length > 1 ? `Upload ${selectedFiles.length} files` : 'Upload' }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
