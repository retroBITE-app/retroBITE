<script setup lang="ts">
import { ref, computed } from 'vue'
import { formatSize } from '@/Helpers/format'

const props = defineProps<{
  console: string
  consoleName: string
  acceptedExtensions: string[]
  uploadDirs: Array<{ value: string; label: string }>
}>()

const emit = defineEmits<{ done: [] }>()

const CHUNK_SIZE = 50 * 1024 * 1024

const open          = ref(false)
const isDragging    = ref(false)
const selectedFiles = ref<File[]>([])
const selectedDir   = ref(props.uploadDirs[0]?.value ?? '')
const uploading     = ref(false)
const progress      = ref(0)
const statusText    = ref('')
const error         = ref<string | null>(null)
const fileInputRef  = ref<HTMLInputElement>()

const totalSize = computed(() =>
  selectedFiles.value.reduce((sum, f) => sum + f.size, 0)
)

function openModal() {
  selectedFiles.value = []
  selectedDir.value   = props.uploadDirs[0]?.value ?? ''
  progress.value      = 0
  error.value         = null
  open.value          = true
}

function closeModal() {
  if (uploading.value) return
  open.value = false
}

function onDragOver(e: DragEvent) {
  e.preventDefault()
  isDragging.value = true
}

function onDragLeave() {
  isDragging.value = false
}

function onDrop(e: DragEvent) {
  e.preventDefault()
  isDragging.value = false
  if (e.dataTransfer?.files) addFiles(e.dataTransfer.files)
}

function onFileInput(e: Event) {
  const files = (e.target as HTMLInputElement).files
  if (files) addFiles(files)
  if (fileInputRef.value) fileInputRef.value.value = ''
}

function addFiles(fileList: FileList) {
  error.value = null
  const rejected: string[] = []

  for (const file of Array.from(fileList)) {
    const ext = file.name.split('.').pop()?.toLowerCase() ?? ''
    if (!props.acceptedExtensions.includes(ext)) {
      rejected.push(file.name)
      continue
    }
    if (!selectedFiles.value.some(f => f.name === file.name && f.size === file.size)) {
      selectedFiles.value.push(file)
    }
  }

  if (rejected.length) {
    error.value = `Skipped ${rejected.length} file${rejected.length > 1 ? 's' : ''} with unsupported extensions`
  }
}

function removeFile(index: number) {
  selectedFiles.value.splice(index, 1)
}

async function startUpload() {
  if (!selectedFiles.value.length) return

  uploading.value = true
  progress.value  = 0
  error.value     = null

  const files      = [...selectedFiles.value]
  const totalBytes = files.reduce((sum, f) => sum + f.size, 0)
  const errors: string[] = []

  let bytesBeforeFile = 0

  for (let fi = 0; fi < files.length; fi++) {
    const file        = files[fi]
    const uploadId    = crypto.randomUUID()
    const totalChunks = Math.ceil(file.size / CHUNK_SIZE)

    try {
      for (let i = 0; i < totalChunks; i++) {
        statusText.value = files.length > 1
          ? `File ${fi + 1} / ${files.length}: ${file.name}`
          : file.name
        const chunk            = file.slice(i * CHUNK_SIZE, (i + 1) * CHUNK_SIZE)
        const bytesBeforeChunk = bytesBeforeFile + i * CHUNK_SIZE

        await sendChunk({
          uploadId,
          filename:    file.name,
          fileSize:    file.size,
          subfolder:   selectedDir.value,
          chunkIndex:  i,
          totalChunks,
          chunk,
          onProgress: (pct: number) => {
            const chunkBytes = Math.round(chunk.size * pct / 100)
            progress.value   = Math.round((bytesBeforeChunk + chunkBytes) / totalBytes * 100)
          },
        })
      }
    } catch (e: unknown) {
      errors.push(`${file.name}: ${e instanceof Error ? e.message : 'failed'}`)
    }

    bytesBeforeFile += file.size
  }

  progress.value  = 100
  uploading.value = false

  if (errors.length) {
    error.value = errors.join(' · ')
  } else {
    open.value = false
    emit('done')
  }
}

function sendChunk(opts: {
  uploadId: string
  filename: string
  fileSize: number
  subfolder: string
  chunkIndex: number
  totalChunks: number
  chunk: Blob
  onProgress: (pct: number) => void
}): Promise<void> {
  return new Promise((resolve, reject) => {
    const form = new FormData()
    form.append('upload_id',    opts.uploadId)
    form.append('filename',     opts.filename)
    form.append('file_size',    String(opts.fileSize))
    form.append('subfolder',    opts.subfolder)
    form.append('chunk_index',  String(opts.chunkIndex))
    form.append('total_chunks', String(opts.totalChunks))
    form.append('chunk',        opts.chunk, opts.filename)

    const xhr = new XMLHttpRequest()
    xhr.open('POST', `/consoles/${props.console}/upload-chunk`)
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest')

    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) opts.onProgress(Math.round((e.loaded / e.total) * 100))
    }

    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve()
      } else {
        try {
          const body = JSON.parse(xhr.responseText)
          reject(new Error(body.error ?? `HTTP ${xhr.status}`))
        } catch {
          reject(new Error(`HTTP ${xhr.status}`))
        }
      }
    }

    xhr.onerror = () => reject(new Error('Network error'))
    xhr.send(form)
  })
}
</script>

<template>
  <button
    @click="openModal"
    class="px-4 py-2 rounded-md text-sm font-medium bg-zinc-700 hover:bg-zinc-600 text-white transition-colors"
  >
    Upload file
  </button>

  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-center justify-center p-4"
    >
      <div class="absolute inset-0 bg-black/60" @click="closeModal" />

      <div class="relative z-10 w-full max-w-lg rounded-xl bg-zinc-900 border border-zinc-700 shadow-2xl">

        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-zinc-800">
          <h2 class="text-base font-semibold text-zinc-100">Upload to {{ consoleName }}</h2>
          <button
            @click="closeModal"
            :disabled="uploading"
            class="text-zinc-500 hover:text-zinc-300 transition-colors disabled:opacity-40"
          >✕</button>
        </div>

        <div class="p-6 space-y-5">

          <!-- Drop zone -->
          <div>
            <div
              @dragover="onDragOver"
              @dragleave="onDragLeave"
              @drop="onDrop"
              @click="fileInputRef?.click()"
              :class="[
                isDragging ? 'border-emerald-500 bg-emerald-500/5' : 'border-zinc-700 hover:border-zinc-500',
                selectedFiles.length ? 'py-4' : 'py-10',
                'rounded-lg border-2 border-dashed text-center cursor-pointer transition-all'
              ]"
            >
              <p class="text-zinc-300 text-sm font-medium">
                {{ selectedFiles.length ? 'Drop more files or click to add' : 'Drag & drop files here' }}
              </p>
              <p v-if="!selectedFiles.length" class="text-zinc-600 text-xs mt-1">or click to browse</p>
            </div>

            <div class="mt-3 flex flex-wrap gap-1.5 items-center">
              <span class="text-xs text-zinc-500">Accepted:</span>
              <span
                v-for="ext in acceptedExtensions"
                :key="ext"
                class="px-1.5 py-0.5 rounded bg-zinc-800 text-zinc-400 font-mono text-xs"
              >.{{ ext }}</span>
            </div>
          </div>

          <!-- File list -->
          <div v-if="selectedFiles.length">
            <div class="flex items-center justify-between mb-2">
              <p class="text-xs font-medium text-zinc-400">
                {{ selectedFiles.length }} file{{ selectedFiles.length > 1 ? 's' : '' }}
                <span class="text-zinc-600 ml-1">{{ formatSize(totalSize) }} total</span>
              </p>
              <button
                @click="selectedFiles = []"
                :disabled="uploading"
                class="text-xs text-zinc-600 hover:text-zinc-400 transition-colors disabled:opacity-40"
              >Clear all</button>
            </div>

            <div class="max-h-40 overflow-y-auto space-y-1 pr-1">
              <div
                v-for="(file, i) in selectedFiles"
                :key="file.name + file.size"
                class="flex items-center justify-between rounded-md bg-zinc-800 border border-zinc-700/60 px-3 py-2"
              >
                <span class="text-sm text-zinc-200 truncate min-w-0 mr-2">{{ file.name }}</span>
                <div class="flex items-center gap-2 shrink-0">
                  <span class="text-xs text-zinc-500">{{ formatSize(file.size) }}</span>
                  <button
                    @click="removeFile(i)"
                    :disabled="uploading"
                    class="text-zinc-600 hover:text-zinc-400 transition-colors disabled:opacity-40 text-xs leading-none"
                  >✕</button>
                </div>
              </div>
            </div>
          </div>

          <!-- Directory selector -->
          <div v-if="uploadDirs.length > 1">
            <p class="text-xs font-medium text-zinc-400 mb-2">Upload to</p>
            <div class="space-y-1">
              <label
                v-for="dir in uploadDirs"
                :key="dir.value"
                class="flex items-center gap-3 rounded-md px-3 py-2 cursor-pointer transition-colors"
                :class="selectedDir === dir.value ? 'bg-zinc-700 text-zinc-100' : 'text-zinc-400 hover:bg-zinc-800'"
              >
                <input type="radio" :value="dir.value" v-model="selectedDir" class="accent-emerald-500" />
                <span class="font-mono text-sm">{{ dir.label }}</span>
              </label>
            </div>
          </div>

          <!-- Progress -->
          <div v-if="uploading" class="space-y-1.5">
            <div class="flex justify-between text-xs text-zinc-400">
              <span class="truncate mr-2">{{ statusText }}</span>
              <span class="shrink-0">{{ progress }}%</span>
            </div>
            <div class="h-1.5 rounded-full bg-zinc-700 overflow-hidden">
              <div
                class="h-full bg-emerald-500 rounded-full transition-all duration-150"
                :style="{ width: progress + '%' }"
              />
            </div>
          </div>

          <!-- Error -->
          <p v-if="error" class="text-xs text-red-400">{{ error }}</p>

        </div>

        <!-- Footer -->
        <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-zinc-800">
          <button
            @click="closeModal"
            :disabled="uploading"
            class="px-4 py-2 rounded-md text-sm text-zinc-400 hover:text-zinc-200 transition-colors disabled:opacity-40"
          >Cancel</button>
          <button
            @click="startUpload"
            :disabled="!selectedFiles.length || uploading"
            class="px-4 py-2 rounded-md text-sm font-medium bg-emerald-600 hover:bg-emerald-500 text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {{ uploading ? 'Uploading…' : selectedFiles.length > 1 ? `Upload ${selectedFiles.length} files` : 'Upload' }}
          </button>
        </div>

      </div>
    </div>
  </Teleport>

  <input
    ref="fileInputRef"
    type="file"
    multiple
    class="hidden"
    :accept="acceptedExtensions.map(e => '.' + e).join(',')"
    @change="onFileInput"
  />
</template>
