<script setup lang="ts">
import { ChevronsUpDown, FolderKanban, Plus, Settings } from '@lucide/vue'
import { useRouter } from 'vue-router'
import {
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  useSidebar,
} from '@/components/ui/sidebar'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useProjectsStore } from '@/stores/projects'
import { useUploadStore } from '@/stores/upload'

const projectsStore = useProjectsStore()
const uploadStore = useUploadStore()
const router = useRouter()
const { isMobile } = useSidebar()

const handleSwitch = (ulid: string) => {
  if (ulid === projectsStore.currentProject?.ulid) return

  // Switching reloads the page, which kills every transfer in flight along with the queue that
  // records them. Losing a multi-gigabyte upload deserves a question, not a surprise.
  if (uploadStore.isUploading && !window.confirm('Uploads are still in progress and will be lost. Switch project anyway?')) {
    return
  }

  projectsStore.setCurrent(ulid)
  window.location.reload()
}

const handleCreate = () => {
  router.push({ name: 'Projects', query: { create: '1' } })
}

const handleManage = () => {
  router.push({ name: 'Projects' })
}
</script>

<template>
  <SidebarMenu>
    <SidebarMenuItem>
      <DropdownMenu>
        <DropdownMenuTrigger as-child>
          <SidebarMenuButton
            size="lg"
            class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
          >
            <!-- The brand mark; the dark file has a lighter tile so it does not sink into a dark sidebar. -->
            <img src="/logo.svg" alt="" class="size-8 shrink-0 dark:hidden" />
            <img src="/logo-dark.svg" alt="" class="hidden size-8 shrink-0 dark:block" />
            <div class="grid flex-1 text-left text-sm leading-tight">
              <span class="truncate font-medium">
                {{ projectsStore.currentProject?.name ?? 'No project' }}
              </span>
              <span class="truncate text-xs text-muted-foreground">NukeVideo</span>
            </div>
            <ChevronsUpDown class="ml-auto size-4" />
          </SidebarMenuButton>
        </DropdownMenuTrigger>
        <DropdownMenuContent
          class="w-[--reka-dropdown-menu-trigger-width] min-w-56 rounded-lg"
          :side="isMobile ? 'bottom' : 'right'"
          align="start"
          :side-offset="4"
        >
          <DropdownMenuLabel class="text-muted-foreground text-xs">
            Projects
          </DropdownMenuLabel>
          <DropdownMenuItem
            v-for="project in projectsStore.projects"
            :key="project.ulid"
            class="gap-2 p-2"
            @click="handleSwitch(project.ulid)"
          >
            <div class="flex size-6 items-center justify-center rounded-sm border">
              <FolderKanban class="size-3.5 shrink-0" />
            </div>
            <span class="truncate">{{ project.name }}</span>
            <span
              v-if="project.ulid === projectsStore.currentProject?.ulid"
              class="ml-auto text-xs text-muted-foreground"
            >
              active
            </span>
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem class="gap-2 p-2" @click="handleCreate">
            <div class="flex size-6 items-center justify-center rounded-md border bg-background">
              <Plus class="size-4" />
            </div>
            <div class="text-muted-foreground font-medium">New project</div>
          </DropdownMenuItem>
          <DropdownMenuItem class="gap-2 p-2" @click="handleManage">
            <div class="flex size-6 items-center justify-center rounded-md border bg-background">
              <Settings class="size-4" />
            </div>
            <div class="text-muted-foreground font-medium">Manage projects</div>
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </SidebarMenuItem>
  </SidebarMenu>
</template>
