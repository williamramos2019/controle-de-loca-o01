
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  server: {
    host: '0.0.0.0',
    port: 5000,
    allowedHosts: [
      'd5820cfe-4518-4ab7-8a43-45c398c8c447-00-181zywabx6yu.spock.replit.dev'
    ]
  }
})
