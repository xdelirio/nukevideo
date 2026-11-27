import HomePage from '@/pages/home/HomePage.vue'
import ProjectsPage from '@/pages/projects/ProjectsPage.vue'
import TemplatesPage from '@/pages/templates/TemplatesPage.vue'
import EditTemplatePage from '@/pages/templates/EditTemplatePage.vue'
import VideoPage from '@/pages/videos/VideoPage.vue'
import VideosPage from '@/pages/videos/VideosPage.vue'
import NodesPage from '@/pages/nodes/NodesPage.vue'
import UsersPage from '@/pages/users/UsersPage.vue'
import AccountPage from '@/pages/settings/AccountPage.vue'
import ActivityLogPage from '@/pages/settings/ActivityLogPage.vue'
import ApiKeysPage from '@/pages/settings/ApiKeysPage.vue'
import AppSettingsPage from '@/pages/settings/AppSettingsPage.vue'
import CdnSettingsPage from '@/pages/settings/CdnSettingsPage.vue'
import LoginPage from '@/pages/auth/LoginPage.vue'
import NotFoundPage from '@/pages/errors/NotFoundPage.vue'
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { name: 'Login', path: '/login', component: LoginPage, meta: { guest: true } },
    // Instance-wide analytics: bandwidth totals, viewer IPs and top videos across every tenant.
    // The API backing it is admin-only, so the page is too.
    { name: 'Home', path: '/', component: HomePage, meta: { admin: true } },
    { name: 'Videos', path: '/videos', component: VideosPage },
    { name: 'Video', path: '/videos/:id', component: VideoPage },
    { name: 'Templates', path: '/templates', component: TemplatesPage },
    { name: 'CreateTemplate', path: '/templates/create', component: EditTemplatePage },
    { name: 'EditTemplate', path: '/templates/:id', component: EditTemplatePage },
    { name: 'Projects', path: '/projects', component: ProjectsPage },
    { name: 'Nodes', path: '/nodes', component: NodesPage, meta: { admin: true } },
    { name: 'Users', path: '/users', component: UsersPage, meta: { admin: true } },
    { name: 'Account', path: '/settings/account', component: AccountPage },
    { name: 'ActivityLog', path: '/settings/activity-log', component: ActivityLogPage },
    { name: 'ApiKeys', path: '/settings/api-keys', component: ApiKeysPage },
    { name: 'AppSettings', path: '/settings/app', component: AppSettingsPage, meta: { admin: true } },
    { name: 'CdnSettings', path: '/settings/cdn', component: CdnSettingsPage, meta: { admin: true } },
    // Last: any path no route above matches. The server answers every unknown path with the
    // panel (it cannot know the panel's routes), so the panel has to say it is not a page.
    { name: 'Not found', path: '/:pathMatch(.*)*', component: NotFoundPage },
  ],
})

/** Where a signed-in user belongs when they have no particular destination. Home is admin-only,
 *  so it cannot double as the fallback — that would bounce a non-admin between the two forever. */
const landingRoute = (isAdmin: boolean) => ({ name: isAdmin ? 'Home' : 'Videos' })

router.beforeEach(async (to) => {
  const authStore = useAuthStore()
  if (!authStore.isAuthenticated && !authStore.loaded) {
    await authStore.fetchUser()
  }

  const isGuest = to.meta.guest === true

  if (isGuest && authStore.isAuthenticated) {
    return landingRoute(authStore.isAdmin)
  }

  if (!isGuest && !authStore.isAuthenticated) {
    return { name: 'Login' }
  }

  if (to.meta.admin && !authStore.isAdmin) {
    return landingRoute(authStore.isAdmin)
  }
})

export default router
