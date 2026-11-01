import { reactive, ref, watch } from 'vue'
import AnalyticsService from '@/services/AnalyticsService'

interface Panels {
  cards: App.Data.Analytics.AnalyticsCardData[]
  bandwidthOverTime: App.Data.Analytics.BandwidthPointData[]
  topIps: App.Data.Analytics.TopIpData[]
  topVideos: App.Data.Analytics.TopVideoData[]
  topTrackingIds: App.Data.Analytics.TopTrackingIdData[]
  topExternalUsers: App.Data.Analytics.TopExternalUserData[]
}

type Panel = keyof Panels

// Each panel from its own endpoint. They are requested together and each one is drawn when it
// lands, so the cards do not wait on the breakdowns and a slow panel holds up nobody else.
const loaders: { [K in Panel]: (from: string, to: string) => Promise<Panels[K]> } = {
  cards: (from, to) => AnalyticsService.summary(from, to),
  bandwidthOverTime: (from, to) => AnalyticsService.bandwidth(from, to),
  topIps: (from, to) => AnalyticsService.topIps(from, to),
  topVideos: (from, to) => AnalyticsService.topVideos(from, to),
  topTrackingIds: (from, to) => AnalyticsService.topTrackingIds(from, to),
  topExternalUsers: (from, to) => AnalyticsService.topExternalUsers(from, to),
}

const panels = Object.keys(loaders) as Panel[]

export function useAnalytics() {
  const data = reactive<Panels>({
    cards: [],
    bandwidthOverTime: [],
    topIps: [],
    topVideos: [],
    topTrackingIds: [],
    topExternalUsers: [],
  })
  // Loading from the start: the page mounts before the first fetch, and an empty panel that is
  // not loading reads as "no traffic".
  const loading = reactive(
    Object.fromEntries(panels.map((p) => [p, true])) as Record<Panel, boolean>,
  )
  const errors = reactive<Partial<Record<Panel, string>>>({})

  const from = ref(getDefaultFrom())
  const to = ref(getDefaultTo())

  // Bumped on every fetch. A response from an earlier range that lands late is dropped rather
  // than drawn over the current one.
  let generation = 0

  function getDefaultFrom(): string {
    const d = new Date()
    d.setDate(d.getDate() - 30)
    return d.toISOString().slice(0, 10)
  }

  function getDefaultTo(): string {
    return new Date().toISOString().slice(0, 10)
  }

  async function load<K extends Panel>(panel: K, current: number) {
    loading[panel] = true
    delete errors[panel]
    try {
      const result = await loaders[panel](from.value, to.value)
      if (current === generation) (data as Panels)[panel] = result
    } catch (e) {
      if (current === generation) errors[panel] = e instanceof Error ? e.message : 'Failed to load'
    } finally {
      if (current === generation) loading[panel] = false
    }
  }

  function fetchAnalytics() {
    const current = ++generation
    for (const panel of panels) load(panel, current)
  }

  watch([from, to], () => fetchAnalytics())

  return {
    data,
    loading,
    errors,
    from,
    to,
    fetchAnalytics,
  }
}
