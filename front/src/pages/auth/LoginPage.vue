<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import AuthService from '@/services/AuthService'
import { ValidationException } from '@/exceptions/ValidationException'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

const form = ref({
  email: '',
  password: '',
})

const errors = ref<Record<string, string[]>>({})
const loading = ref(false)

async function handleLogin() {
  errors.value = {}
  loading.value = true

  try {
    await AuthService.login(form.value)
    await auth.fetchUser()
    router.push('/')
  } catch (error) {
    if (error instanceof ValidationException) {
      errors.value = error.errors
    }
    console.error(error)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center px-4">
    <Card class="w-full max-w-md">
      <CardHeader class="text-center">
        <img src="/logo.svg" alt="NukeVideo" class="mx-auto mb-2 size-12 dark:hidden" />
        <img src="/logo-dark.svg" alt="NukeVideo" class="mx-auto mb-2 hidden size-12 dark:block" />
        <CardTitle class="text-2xl">Login</CardTitle>
        <CardDescription>Enter your credentials to access your account</CardDescription>
      </CardHeader>
      <CardContent>
        <form @submit.prevent="handleLogin" class="grid gap-4">
          <div class="grid gap-2">
            <Label for="email">Email</Label>
            <Input id="email" v-model="form.email" type="email" placeholder="email@example.com" required />
            <p v-if="errors.email" class="text-sm text-destructive">{{ errors.email[0] }}</p>
          </div>

          <div class="grid gap-2">
            <Label for="password">Password</Label>
            <Input id="password" v-model="form.password" type="password" placeholder="••••••••" required />
            <p v-if="errors.password" class="text-sm text-destructive">{{ errors.password[0] }}</p>
          </div>

          <Button type="submit" class="w-full" :disabled="loading">
            {{ loading ? 'Loading...' : 'Login' }}
          </Button>
        </form>
      </CardContent>
    </Card>
  </div>
</template>
