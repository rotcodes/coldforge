import { useMemo, useRef, useState } from 'react';
import DOMPurify from 'dompurify';
import { ImageOff } from 'lucide-react';
import { Button } from '@/Components/ui/button';

type Props = {
    html: string;
    subject?: string | null;
};

const remoteImagePattern = /<(?:img|source)\b[^>]+(?:src|srcset)\s*=\s*["']?https?:/i;

function emailDocument(html: string, loadRemoteImages: boolean): string {
    const sanitized = DOMPurify.sanitize(html, {
        WHOLE_DOCUMENT: true,
        FORBID_TAGS: ['script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'video', 'audio'],
        FORBID_ATTR: ['srcdoc'],
    });
    const document = new DOMParser().parseFromString(sanitized, 'text/html');

    document.querySelectorAll('a').forEach((link) => {
        link.target = '_blank';
        link.rel = 'noopener noreferrer nofollow';
    });
    document.querySelectorAll<HTMLImageElement>('img').forEach((image) => {
        const source = image.getAttribute('src') ?? '';
        if (!loadRemoteImages && /^https?:/i.test(source)) {
            image.dataset.remoteSrc = source;
            image.removeAttribute('src');
            image.alt = image.alt ? `${image.alt} (remote image blocked)` : 'Remote image blocked';
        }
        image.style.maxWidth = '100%';
        image.style.height = 'auto';
    });

    const policy = document.createElement('meta');
    policy.httpEquiv = 'Content-Security-Policy';
    policy.content = [
        "default-src 'none'",
        `img-src data: cid:${loadRemoteImages ? ' https: http:' : ''}`,
        `font-src data:${loadRemoteImages ? ' https: http:' : ''}`,
        "style-src 'unsafe-inline'",
        "form-action 'none'",
        "base-uri 'none'",
    ].join('; ');
    document.head.prepend(policy);

    const defaults = document.createElement('style');
    defaults.textContent = 'html,body{min-height:0}body{margin:0;padding:24px;overflow-wrap:anywhere}table{max-width:100%}pre{white-space:pre-wrap}';
    document.head.append(defaults);

    return `<!doctype html>${document.documentElement.outerHTML}`;
}

export default function EmailHtml({ html, subject }: Props) {
    const frame = useRef<HTMLIFrameElement>(null);
    const [loadRemoteImages, setLoadRemoteImages] = useState(false);
    const hasRemoteImages = remoteImagePattern.test(html);
    const source = useMemo(() => emailDocument(html, loadRemoteImages), [html, loadRemoteImages]);

    const resize = () => {
        const body = frame.current?.contentDocument?.body;
        const root = frame.current?.contentDocument?.documentElement;
        if (!frame.current || !body || !root) return;
        frame.current.style.height = `${Math.min(Math.max(body.scrollHeight, root.scrollHeight, 120), 1200)}px`;
    };

    return (
        <div>
            {hasRemoteImages && !loadRemoteImages && (
                <div className="mb-2 flex items-center justify-between gap-3 rounded-md border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                    <span className="flex items-center gap-2"><ImageOff className="size-3.5" /> Remote images are blocked for privacy.</span>
                    <Button type="button" size="sm" variant="outline" onClick={() => setLoadRemoteImages(true)}>Show images</Button>
                </div>
            )}
            <iframe
                ref={frame}
                title={subject ? `Email: ${subject}` : 'Email message'}
                srcDoc={source}
                sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                referrerPolicy="no-referrer"
                onLoad={resize}
                className="min-h-[120px] w-full rounded-md border bg-white"
            />
        </div>
    );
}
