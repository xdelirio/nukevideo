import type { Pagination } from '@/types/Pagination'
import apiClient from './api'

class ActivityLogService {
  private readonly BASE_PATH = '/activity-log'

  constructor(private api = apiClient) {}

  async index(
    page = 1,
    filters: Partial<App.Data.ActivityLog.IndexActivityLogData> = {},
  ): Promise<Pagination<App.Data.ActivityLogData>> {
    const res = await this.api.get(this.BASE_PATH, { params: { page, ...filters } })
    return res.data
  }
}

export default new ActivityLogService()
