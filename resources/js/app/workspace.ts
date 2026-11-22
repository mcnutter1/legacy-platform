import { createContext, useContext } from 'react';
import type { Me } from '../api/types';

export interface WorkspaceCtx {
  id: string;
  name: string;
  role: string;
  me: Me;
  path: (p: string) => string;
}

export const WorkspaceContext = createContext<WorkspaceCtx | null>(null);

export function useWorkspace(): WorkspaceCtx {
  const c = useContext(WorkspaceContext);
  if (!c) throw new Error('useWorkspace outside a workspace');
  return c;
}
