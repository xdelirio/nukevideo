<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import Spinner from '@/components/ui/spinner/Spinner.vue'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import TemplateService from '@/services/TemplateService'
type Template = App.Data.TemplateData
type TemplatePreset = App.Data.TemplatePresetData
import { Copy, Edit, GripVertical, MoreVertical, Plus, Download } from '@lucide/vue'
import { Switch } from '@/components/ui/switch'
import DeleteTemplateButton from './components/DeleteTemplateButton.vue'
import { toast } from 'vue-sonner'

const router = useRouter()
const templates = ref<Template[]>([])
const presets = ref<TemplatePreset[]>([])
const loading = ref(true)
const loadingPresets = ref(true)
const adoptingSlug = ref<string | null>(null)
const duplicatingUlid = ref<string | null>(null)

const fetchTemplates = async () => {
  try {
    loading.value = true
    templates.value = await TemplateService.index()
  } catch (error) {
    console.error('Error fetching templates:', error)
  } finally {
    loading.value = false
  }
}

const fetchPresets = async () => {
  try {
    loadingPresets.value = true
    presets.value = await TemplateService.presets()
  } catch (error) {
    console.error('Error fetching presets:', error)
  } finally {
    loadingPresets.value = false
  }
}

const adoptPreset = async (slug: string) => {
  try {
    adoptingSlug.value = slug
    await TemplateService.adoptPreset(slug)
    toast.success('Template added to your collection')
    await fetchTemplates()
  } catch (error) {
    console.error('Error adopting preset:', error)
    toast.error('Failed to add template')
  } finally {
    adoptingSlug.value = null
  }
}

/**
 * A template that videos were encoded with can never be deleted, so retiring one is how it leaves
 * the picker. Applied optimistically — the switch has to feel immediate — and rolled back if the
 * request fails.
 */
const toggleEnabled = async (template: Template, enabled: boolean) => {
  const previous = template.enabled
  template.enabled = enabled

  try {
    await TemplateService.setEnabled(template.ulid, enabled)
    toast.success(enabled ? 'Template enabled' : 'Template disabled: new uploads can no longer use it')
  } catch (error) {
    console.error('Error updating template:', error)
    template.enabled = previous
    toast.error('Failed to update the template')
  }
}

const handleDuplicate = async (template: Template) => {
  try {
    duplicatingUlid.value = template.ulid
    const copy = await TemplateService.duplicate(template.ulid)
    toast.success(`Created "${copy.name}"`)
    await fetchTemplates()
  } catch (error) {
    console.error('Error duplicating template:', error)
    toast.error('Failed to duplicate the template')
  } finally {
    duplicatingUlid.value = null
  }
}

// --- Ordering (drag and drop) ---
// Rows are only draggable while their grip is held: a row-wide `draggable` swallows the text
// selection and the clicks on the actions menu.
const draggingUlid = ref<string | null>(null)
const dragIndex = ref<number | null>(null)
const dropIndex = ref<number | null>(null)

const onDragStart = (index: number, event: DragEvent) => {
  dragIndex.value = index

  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move'
    // Firefox will not start a drag at all unless some payload is attached.
    event.dataTransfer.setData('text/plain', String(index))
  }
}

const onDragOver = (index: number) => {
  if (dragIndex.value !== null) dropIndex.value = index
}

const onDragEnd = () => {
  draggingUlid.value = null
  dragIndex.value = null
  dropIndex.value = null
}

const onDrop = async (index: number) => {
  const from = dragIndex.value
  onDragEnd()

  if (from === null || from === index) return

  const previous = [...templates.value]
  const next = [...templates.value]
  const [moved] = next.splice(from, 1)
  if (!moved) return

  next.splice(index, 0, moved)
  templates.value = next

  try {
    // The response is the stored order, so a concurrent change elsewhere lands here too.
    templates.value = await TemplateService.reorder(next.map(template => template.ulid))
  } catch (error) {
    console.error('Error reordering templates:', error)
    templates.value = previous
    toast.error('Failed to save the new order')
  }
}

const formatDate = (dateString?: string): string => {
  if (!dateString) return 'N/A'
  const date = new Date(dateString)
  return date.toLocaleDateString()
}

const formatVariantsSummary = (preset: TemplatePreset): string => {
  const output = preset.query.outputs[0]
  if (!output) return ''
  const dimensions = output.variants.map((v: Record<string, unknown>) => {
    const w = v.width
    const h = v.height
    if (w && h) return h
    return ''
  }).filter(Boolean)
  return dimensions.join(', ')
}

const handleEdit = (template: Template) => {
  router.push({ name: 'EditTemplate', params: { id: template.ulid } })
}

const handleCreate = () => {
  router.push({ name: 'CreateTemplate' })
}

const handleDeleteSuccess = () => {
  fetchTemplates()
}

onMounted(() => {
  fetchTemplates()
  fetchPresets()
})
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <!-- Header -->
    <div class="flex justify-between items-center">
      <div>
        <h1 class="text-2xl font-bold">Templates</h1>
        <p class="text-muted-foreground">Manage your encoding templates</p>
      </div>
      <Button @click="handleCreate">
        <Plus :size="16" class="mr-2" />
        Create Template
      </Button>
    </div>

    <!-- My Templates Table -->
    <div class="overflow-hidden rounded-lg border">
      <Table>
        <TableHeader class="bg-muted sticky top-0 z-10">
          <TableRow>
            <TableHead class="w-10"></TableHead>
            <TableHead>Name</TableHead>
            <TableHead>Status</TableHead>
            <TableHead>Created</TableHead>
            <TableHead class="text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-if="loading">
            <TableCell colspan="5" class="text-center">
              <Spinner />
              Loading templates...
            </TableCell>
          </TableRow>
          <TableRow v-else-if="templates.length === 0">
            <TableCell colspan="5" class="text-center text-muted-foreground py-8">
              <div class="flex flex-col items-center gap-2">
                <p>No templates found</p>
                <Button variant="outline" size="sm" @click="handleCreate">
                  <Plus :size="14" class="mr-2" />
                  Create your first template
                </Button>
              </div>
            </TableCell>
          </TableRow>
          <TableRow
            v-else
            v-for="(template, index) in templates"
            :key="template.ulid"
            :draggable="draggingUlid === template.ulid"
            class="hover:bg-muted/50"
            :class="[
              dragIndex === index ? 'opacity-50' : '',
              dropIndex === index && dragIndex !== index ? 'border-t-2 border-primary' : '',
            ]"
            @dragstart="onDragStart(index, $event)"
            @dragover.prevent="onDragOver(index)"
            @drop.prevent="onDrop(index)"
            @dragend="onDragEnd"
          >
            <TableCell>
              <button
                type="button"
                class="cursor-grab text-muted-foreground hover:text-foreground active:cursor-grabbing"
                aria-label="Reorder template"
                @mousedown="draggingUlid = template.ulid"
                @mouseup="draggingUlid = null"
              >
                <GripVertical :size="16" />
              </button>
            </TableCell>
            <TableCell class="font-medium" :class="template.enabled ? '' : 'text-muted-foreground'">
              {{ template.name }}
              <Badge variant="outline" class="ml-1.5 text-[10px] px-1.5 py-0 font-normal" title="Encoding version">v{{ template.version }}</Badge>
            </TableCell>
            <TableCell>
              <div class="flex items-center gap-2">
                <Switch
                  :model-value="template.enabled"
                  :aria-label="template.enabled ? 'Disable template' : 'Enable template'"
                  @update:model-value="toggleEnabled(template, $event)"
                />
                <span class="text-sm text-muted-foreground">
                  {{ template.enabled ? 'Enabled' : 'Disabled' }}
                </span>
              </div>
            </TableCell>
            <TableCell>
              {{ formatDate(template.createdAt) }}
            </TableCell>
            <TableCell class="text-right">
              <DropdownMenu>
                <DropdownMenuTrigger as-child>
                  <Button variant="ghost" size="icon">
                    <MoreVertical :size="16" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem @click="handleEdit(template)">
                    <Edit :size="16" class="mr-2" />
                    Edit
                  </DropdownMenuItem>
                  <DropdownMenuItem :disabled="duplicatingUlid === template.ulid" @click="handleDuplicate(template)">
                    <Copy :size="16" class="mr-2" />
                    Duplicate
                  </DropdownMenuItem>
                  <DeleteTemplateButton :template="template" @deleted="handleDeleteSuccess" />
                </DropdownMenuContent>
              </DropdownMenu>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>

    <!-- Presets Section -->
    <div class="flex flex-col gap-4">
      <div>
        <h2 class="text-xl font-semibold">Presets</h2>
        <p class="text-muted-foreground text-sm">Ready-to-use encoding templates. Add any preset to your collection.</p>
      </div>

      <div v-if="loadingPresets" class="flex justify-center py-8">
        <Spinner />
      </div>

      <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <Card v-for="preset in presets" :key="preset.slug">
          <CardHeader class="pb-3">
            <div class="flex items-start justify-between">
              <CardTitle class="text-base">{{ preset.name }}</CardTitle>
              <Badge variant="secondary" class="text-xs">{{ preset.category }}</Badge>
            </div>
            <CardDescription>{{ preset.description }}</CardDescription>
          </CardHeader>
          <CardContent class="pb-3">
            <div class="flex flex-wrap gap-2 text-xs">
              <Badge variant="outline">{{ formatVariantsSummary(preset) }}</Badge>
            </div>
          </CardContent>
          <CardFooter>
            <Button variant="outline" size="sm" class="w-full" :disabled="adoptingSlug === preset.slug"
              @click="adoptPreset(preset.slug)">
              <Spinner v-if="adoptingSlug === preset.slug" class="mr-2" />
              <Download v-else :size="14" class="mr-2" />
              {{ adoptingSlug === preset.slug ? 'Adding...' : 'Use this template' }}
            </Button>
          </CardFooter>
        </Card>
      </div>
    </div>
  </div>
</template>
