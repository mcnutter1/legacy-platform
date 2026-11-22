import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import { api, ws } from '../api/client';
import { useWorkspace } from '../app/workspace';
import { Card, FormError, Notice, PageHeader } from '../components/ui';
import { ResourceSection, useScopes } from '../components/resource';

const DOC_TYPES = ['statement', 'paystub', 'w2', '1099', 'k1', 'tax_return', 'tax_form', 'receipt', 'invoice', 'contract', 'deed', 'title', 'appraisal', 'policy', 'declarations', 'will', 'trust', 'poa', 'directive', 'id', 'insurance_card', 'warranty', 'photo', 'correspondence', 'other'];

export default function Vault() {
  const { id, path } = useWorkspace();
  const nav = useNavigate();
  const qc = useQueryClient();
  const { scopes } = useScopes('vault');
  const [scope, setScope] = useState('');
  const [docType, setDocType] = useState('other');
  const [taxYear, setTaxYear] = useState('');
  const [files, setFiles] = useState<FileList | null>(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<unknown>(null);
  const [done, setDone] = useState(0);
  const upload = async (e: FormEvent) => {
    e.preventDefault();
    if (!files?.length) return;
    setBusy(true); setErr(null); setDone(0);
    try {
      for (const f of Array.from(files)) {
        const form = new FormData();
        form.set('file', f);
        form.set('scope_id', scope || scopes[0]?.id || '');
        form.set('doc_type', docType);
        if (taxYear) form.set('tax_year', taxYear);
        await api(ws(id, 'vault/documents'), { method: 'POST', form });
        setDone((n) => n + 1);
      }
      void qc.invalidateQueries({ queryKey: [id] });
    } catch (x) {
      setErr(x);
    } finally {
      setBusy(false);
    }
  };
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Family" title="Vault" description="Encrypted document storage with versioning, retention and legal holds. Files are scanned before anyone can open them." />
      <Card title="Upload">
        <form onSubmit={upload} className="grid grid-cols-1 gap-4 sm:grid-cols-4">
          <label className="field"><span>Scope</span><select value={scope} onChange={(e) => setScope(e.target.value)}>{scopes.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></label>
          <label className="field"><span>Type</span><select value={docType} onChange={(e) => setDocType(e.target.value)}>{DOC_TYPES.map((t) => <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>)}</select></label>
          <label className="field"><span>Tax year (optional)</span><input inputMode="numeric" value={taxYear} onChange={(e) => setTaxYear(e.target.value)} /></label>
          <label className="field"><span>Files</span><input type="file" multiple onChange={(e) => setFiles(e.target.files)} required /></label>
          <div className="flex items-center gap-3 sm:col-span-4">
            <button className="btn-primary" disabled={busy || !files?.length}>{busy ? `Uploading ${done + 1} of ${files?.length}…` : 'Upload'}</button>
            {!busy && done > 0 && <span className="text-sm text-positive" role="status">{done} file(s) uploaded and queued for scanning.</span>}
          </div>
          <div className="sm:col-span-4"><FormError error={err} /></div>
        </form>
        <div className="mt-4"><Notice>Each file is encrypted with its own key. Downloads use short-lived single-use links and are logged.</Notice></div>
      </Card>
      <ResourceSection type="documents" onOpen={(r) => nav(path(`vault/${r.id}`))} />
    </div>
  );
}
