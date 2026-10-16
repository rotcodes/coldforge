import { useSyncExternalStore } from 'react';

type Theme = 'dark' | 'light';

const listeners = new Set<() => void>();

const read = (): Theme => (document.documentElement.classList.contains('dark') ? 'dark' : 'light');

export function setTheme(theme: Theme) {
    document.documentElement.classList.toggle('dark', theme === 'dark');
    try {
        localStorage.setItem('theme', theme);
    } catch {}
    listeners.forEach((l) => l());
}

export function useTheme() {
    const theme = useSyncExternalStore(
        (cb) => {
            listeners.add(cb);
            return () => listeners.delete(cb);
        },
        read,
        () => 'dark' as Theme,
    );
    return { theme, setTheme, toggle: () => setTheme(theme === 'dark' ? 'light' : 'dark') };
}
