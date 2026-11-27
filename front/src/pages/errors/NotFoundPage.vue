<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { Button } from '@/components/ui/button'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const authStore = useAuthStore()

// The same landing the router sends a signed-in user to: Home is admin-only.
const home = computed(() => ({ name: authStore.isAdmin ? 'Home' : 'Videos' }))
</script>

<template>
  <div class="flex flex-1 flex-col items-center justify-center gap-4 p-8 text-center">
    <p class="text-sm font-medium text-muted-foreground">404</p>
    <h2 class="text-2xl font-semibold">Page not found</h2>
    <p class="max-w-md text-sm text-muted-foreground">
      Nothing lives at <code class="rounded bg-muted px-1 py-0.5">{{ route.fullPath }}</code>.
    </p>
    <Button as-child>
      <RouterLink :to="home">Go back</RouterLink>
    </Button>
  </div>
</template>
