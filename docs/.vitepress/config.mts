import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'NukeVideo',
  description: 'Open-source video processing and delivery engine',

  themeConfig: {
    logo: '🎬',
    nav: [
      { text: 'Guide', link: '/guide/what-is-nukevideo' },
      { text: 'API Reference', link: '/api/authentication' },
    ],

    sidebar: {
      '/guide/': [
        {
          text: 'Introduction',
          items: [
            { text: 'What is NukeVideo?', link: '/guide/what-is-nukevideo' },
            { text: 'Getting Started', link: '/guide/getting-started' },
          ],
        },
        {
          text: 'Core Concepts',
          items: [
            { text: 'Video Processing', link: '/guide/video-processing' },
            { text: 'Templates', link: '/guide/templates' },
            { text: 'Nodes', link: '/guide/nodes' },
            { text: 'Streaming & VOD', link: '/guide/streaming' },
            { text: 'CDN & Delivery', link: '/guide/cdn' },
          ],
        },
        {
          text: 'Deployment',
          items: [
            { text: 'Configuration', link: '/guide/configuration' },
          ],
        },
      ],
      '/api/': [
        {
          text: 'API Reference',
          items: [
            { text: 'Authentication', link: '/api/authentication' },
            { text: 'Integrating', link: '/api/integration' },
            { text: 'Videos', link: '/api/videos' },
            { text: 'Streams', link: '/api/streams' },
            { text: 'Templates', link: '/api/templates' },
            { text: 'Nodes', link: '/api/nodes' },
            { text: 'Users', link: '/api/users' },
            { text: 'External Users', link: '/api/external-users' },
            { text: 'Storage', link: '/api/storage' },
            { text: 'Analytics', link: '/api/analytics' },
            { text: 'Usage', link: '/api/analytics#usage' },
            { text: 'Webhooks', link: '/api/webhooks' },
          ],
        },
      ],
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/chikenare/nukevideo' },
    ],

    search: {
      provider: 'local',
    },

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © 2025 NukeVideo',
    },
  },
})
