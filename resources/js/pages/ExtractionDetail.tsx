import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import { api, ws } from '../api/client';
import { useResource } from '../api/hooks';
import { useWorkspace } from '../app/workspace';
import { Card, FormError, Notice, PageHeader, Query } from '../components/ui';
import { ExtractionReview } from './DocumentDetail';

/** One extraction draft, including values provided by a connected assistant that may have no document yet. */
export default function ExtractionDetail() {
  const { id = '' } = useParams();
  const { id: wsId, path } = useWorkspace();
  const qc = useQueryClient();
  const q = useResource('document_extractions', id);
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<unknown>(null);
  return (
    <Query q={q}>
      {(ex) => (
        <div className="space-y-6">
          <PageHeader eyebrow="Review" title="Confirm paystub values"
            description={ex.engine === 'assistant' ? `${String(ex.model ?? 'A connected app')} read these values from your paystub. Compare them with the paystub, correct anything that differs, then approve.` : undefined}
            actions={ex.document_id ? <Link className="btn-secondary btn-sm" to={path(`vault/${ex.document_id}`)}>Open paper copy</Link> : undefined} />
          {!ex.document_id && ex.status === 'needs_review' && (
            <Card title="Paper copy" subtitle="Optional. Keeps the paystub with the approved paycheck as evidence.">
              <form className="flex flex-wrap items-end gap-3" onSubmit={async (e) => {
                e.preventDefault();
                if (!file) return;
                setBusy(true); setErr(null);
                try {
                  const form = new FormData();
                  form.set('file', file);
                  form.set('scope_id', String(ex.scope_id ?? ''));
                  form.set('doc_type', 'paystub');
                  form.set('origin', 'upload');
                  const up = await api<{ data: { document_id: string } }>(ws(wsId, 'vault/documents'), { method: 'POST', form });
                  await api(ws(wsId, `vault/extractions/${id}/attach-copy`), { body: { document_id: up.data.document_id } });
                  await qc.invalidateQueries({ queryKey: [wsId] });
                } catch (x) { setErr(x); } finally { setBusy(false); }
              }}>
                <label className="field min-w-64 flex-1"><span>Paystub PDF or photo</span>
                  <input type="file" accept="application/pdf,image/*" capture="environment" onChange={(e) => setFile(e.target.files?.[0] ?? null)} required /></label>
                <button className="btn-secondary" disabled={!file || busy}>{busy ? 'Uploading…' : 'Attach copy'}</button>
              </form>
              <FormError error={err} />
            </Card>
          )}
          {ex.engine === 'assistant' && ex.status === 'needs_review' && <Notice>Values typed by an assistant can contain reading mistakes. The checks below compare net pay with gross minus deductions and this paycheck with your prior year-to-date.</Notice>}
          {((ex.checks ?? []) as { check: string; passed: boolean; message: string }[]).filter((c) => !c.passed).length > 0 && ex.status === 'needs_review' && (
            <Notice tone="warning"><ul className="list-disc pl-5">{((ex.checks ?? []) as { check: string; passed: boolean; message: string }[]).filter((c) => !c.passed).map((c) => <li key={c.check}>{c.message}</li>)}</ul></Notice>
          )}
          <ExtractionReview ex={ex} />
        </div>
      )}
    </Query>
  );
}
