import '@xyflow/react/dist/style.css';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { StructureScreen } from '@/kit/structure-screen';
import type { StructurePayload } from '@/kit/types';

const root = document.getElementById('kit-structure-root');
const payload = document.getElementById('kit-structure')?.textContent;

if (root !== null && payload) {
    createRoot(root).render(
        <StrictMode>
            <StructureScreen
                payload={JSON.parse(payload) as StructurePayload}
            />
        </StrictMode>,
    );
}
