import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'NukeVideo',
  description: 'Open-source video processing and delivery engine',

  head: [
    ['link', { rel: 'icon', href: '/favicon.ico', sizes: '48x48' }],
    ['link', { rel: 'icon', href: '/brand/mark.svg', type: 'image/svg+xml' }],
  ],

  // Local working notes (gitignored), not documentation: kept out of the build so a docs build
  // from a working copy cannot publish them.
  srcExclude: ['superpowers/**'],

  themeConfig: {
    logo: { light: '/brand/mark.svg', dark: '/brand/mark-dark.svg', alt: 'NukeVideo' },
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
      { icon: 'github', link: 'https://github.com/xdelirio/nukevideo' },
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
