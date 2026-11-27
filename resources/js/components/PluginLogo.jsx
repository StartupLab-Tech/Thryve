// Logos of the outside apps people can connect. They are the apps' own marks, so they are drawn here and not taken from the brand sprite.
const GITHUB = 'M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12';

const MARKS = {
    github: <path d={GITHUB} fill="currentColor" />,
    'google-drive': (
        <>
            <path fill="#FBBC04" d="M12 3h4.2l6 10.4h-4.2z" />
            <path fill="#34A853" d="M12 3H7.8l-6 10.4H6z" />
            <path fill="#4285F4" d="M6 13.4h16l-2.1 3.6H8.1L6 20.6 3.9 17z" />
        </>
    ),
    'google-docs': (
        <>
            <path fill="#4285F4" d="M6 2h8l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" />
            <path fill="#A1C2FA" d="M14 2l5 5h-4a1 1 0 0 1-1-1z" />
            <path fill="#fff" d="M7 11h10v1.6H7zm0 3.2h10v1.6H7zm0 3.2h6.5V19H7z" />
        </>
    ),
    'google-gmail': (
        <>
            <path fill="#4285F4" d="M2 6.5v11A1.5 1.5 0 0 0 3.5 19H6V9.9z" />
            <path fill="#34A853" d="M22 6.5v11a1.5 1.5 0 0 1-1.5 1.5H18V9.9z" />
            <path fill="#EA4335" d="M2 6.5 12 14l10-7.5V5.5A1.5 1.5 0 0 0 20.5 4h-.3L12 10.1 3.8 4h-.3A1.5 1.5 0 0 0 2 5.5z" />
            <path fill="#C5221F" d="M18 9.9V19h-0.01zM6 9.9V19z" />
            <path fill="#FBBC04" d="M18 5.3V9.9l4-3.4V5.5c0-.9-.7-1.5-1.5-1.5z" />
        </>
    ),
    'google-sheets': (
        <>
            <path fill="#0F9D58" d="M6 2h8l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" />
            <path fill="#87CEAC" d="M14 2l5 5h-4a1 1 0 0 1-1-1z" />
            <path fill="#fff" d="M7 11h10v8H7zm1.5 1.5v1.8h2.8v-1.8zm4.2 0v1.8h2.8v-1.8zM8.5 15.7v1.8h2.8v-1.8zm4.2 0v1.8h2.8v-1.8z" />
        </>
    ),
};

// Marks drawn in their own colours sit on a light tile; the single-colour ones on a dark tile.
const LIGHT = new Set(['google-drive', 'google-docs', 'google-sheets', 'google-gmail']);

export default function PluginLogo({ slug, name }) {
    return (
        <span className={`pl-logo${LIGHT.has(slug) ? ' light' : ''}`} aria-hidden="true">
            {MARKS[slug]
                ? <svg viewBox="0 0 24 24" focusable="false">{MARKS[slug]}</svg>
                : <span>{(name ?? '?').slice(0, 1).toUpperCase()}</span>}
        </span>
    );
}
