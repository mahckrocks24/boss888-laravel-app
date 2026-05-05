import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import App from './App'

// Inject global styles
const style = document.createElement('style')
style.textContent = `
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg: #0F1117; --s1: #171A21; --s2: #1E2230; --s3: #252A3A;
    --p: #6C5CE7; --ac: #00E5A8; --bl: #3B8BF5; --am: #F59E0B; --rd: #F87171; --pu: #A78BFA;
    --text: #E2E8F0; --muted: #94A3B8; --border: #2D3748;
  }
  html, body { height: 100%; background: var(--bg); color: var(--text); }
  body { font-family: 'DM Sans', system-ui, -apple-system, sans-serif; line-height: 1.6; }
  a { text-decoration: none; color: inherit; }
  button { font-family: inherit; }
  textarea { font-family: inherit; }
  select { font-family: inherit; }
  @keyframes spin { to { transform: rotate(360deg); } }

  /* Scrollbar */
  ::-webkit-scrollbar { width: 6px; height: 6px; }
  ::-webkit-scrollbar-track { background: var(--s1); }
  ::-webkit-scrollbar-thumb { background: var(--s3); border-radius: 3px; }

  /* lu-card base */
  .lu-card {
    background: var(--s1);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 20px;
    transition: border-color .15s;
  }
  .lu-card:hover { border-color: rgba(108,92,231,.3); }
`
document.head.appendChild(style)

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>
)
