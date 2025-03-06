import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { LayoutOne } from './layoutone'
import { FileManager } from './filemanager'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <LayoutOne>
      <FileManager />
    </LayoutOne>
  </StrictMode>
)
