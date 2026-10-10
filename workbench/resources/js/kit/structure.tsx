import '@xyflow/react/dist/style.css';
import { ReactFlowProvider } from '@xyflow/react';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { StructureScreen } from '@/kit/structure-screen';
import type { StructurePayload } from '@/kit/types';

const root = document.getElementById('kit-structure-root');
const payload = document.getElementById('kit-structure')?.textContent;

if (root !== null && payload) {
    createRoot(root).render(
        <StrictMode>
            <ReactFlowProvider>
                <TooltipProvider delayDuration={300}>
                    <StructureScreen
                        payload={JSON.parse(payload) as StructurePayload}
                    />
                    <Toaster
                        theme="system"
                        position="bottom-center"
                        duration={2500}
                        richColors
                    />
                </TooltipProvider>
            </ReactFlowProvider>
        </StrictMode>,
    );
}
