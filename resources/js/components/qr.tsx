import { useEffect, useState } from 'react';

/** Renders a QR code locally (the payload never leaves the browser). */
export function QrCode({ value, size = 208, label }: { value: string; size?: number; label: string }) {
  const [src, setSrc] = useState<string | null>(null);
  const [failed, setFailed] = useState(false);
  useEffect(() => {
    let live = true;
    setSrc(null);
    import('qrcode')
      .then((m) => m.toDataURL(value, { width: size * 2, margin: 1, errorCorrectionLevel: 'M', color: { dark: '#000000', light: '#ffffff' } }))
      .then((url) => { if (live) setSrc(url); })
      .catch(() => { if (live) setFailed(true); });
    return () => { live = false; };
  }, [value, size]);
  if (failed) return <p className="text-sm text-negative">Could not draw the QR code. Use the setup key instead.</p>;
  return src
    ? <img src={src} width={size} height={size} alt={label} className="rounded-lg border border-line bg-white p-2" />
    : <div style={{ width: size, height: size }} className="animate-pulse rounded-lg bg-sunken" aria-label="Generating QR code" role="img" />;
}
