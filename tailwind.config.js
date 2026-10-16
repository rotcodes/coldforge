import animate from 'tailwindcss-animate';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: ['./resources/views/**/*.blade.php', './resources/js/**/*.tsx'],
    theme: {
        container: { center: true, padding: '1rem' },
        extend: {
            fontFamily: {
                sans: ['"Inter Variable"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                mono: ['"JetBrains Mono Variable"', 'ui-monospace', 'monospace'],
            },
            colors: {
                border: 'hsl(var(--border))',
                input: 'hsl(var(--input))',
                ring: 'hsl(var(--ring))',
                background: 'hsl(var(--background))',
                foreground: 'hsl(var(--foreground))',
                primary: { DEFAULT: 'hsl(var(--primary))', foreground: 'hsl(var(--primary-foreground))' },
                secondary: { DEFAULT: 'hsl(var(--secondary))', foreground: 'hsl(var(--secondary-foreground))' },
                destructive: { DEFAULT: 'hsl(var(--destructive))', foreground: 'hsl(var(--destructive-foreground))' },
                muted: { DEFAULT: 'hsl(var(--muted))', foreground: 'hsl(var(--muted-foreground))' },
                accent: { DEFAULT: 'hsl(var(--accent))', foreground: 'hsl(var(--accent-foreground))' },
                popover: { DEFAULT: 'hsl(var(--popover))', foreground: 'hsl(var(--popover-foreground))' },
                card: { DEFAULT: 'hsl(var(--card))', foreground: 'hsl(var(--card-foreground))' },
            },
            borderRadius: {
                '2xl': '16px',
                xl: '12px',
                lg: 'var(--radius)',
                md: 'calc(var(--radius) - 2px)',
                sm: 'calc(var(--radius) - 4px)',
            },
            backgroundImage: {
                brand: 'linear-gradient(135deg, #6366f1 0%, #a855f7 100%)',
            },
            boxShadow: {
                glow: '0 8px 24px -8px rgba(99, 102, 241, 0.55)',
                'glow-lg': '0 14px 32px -10px rgba(139, 92, 246, 0.7)',
            },
        },
    },
    plugins: [animate],
};
