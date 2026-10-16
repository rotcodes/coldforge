import { motion, useMotionValue, useSpring, useTransform } from 'framer-motion';
import { cn } from '@/lib/utils';

/** Glass card that tilts up to 4deg toward the cursor. */
export default function TiltCard({ className, children, hero = false }: { className?: string; children: React.ReactNode; hero?: boolean }) {
    const x = useMotionValue(0.5);
    const y = useMotionValue(0.5);
    const rotateX = useSpring(useTransform(y, [0, 1], [4, -4]), { stiffness: 250, damping: 20 });
    const rotateY = useSpring(useTransform(x, [0, 1], [-4, 4]), { stiffness: 250, damping: 20 });

    return (
        <motion.div
            onMouseMove={(e) => {
                const r = e.currentTarget.getBoundingClientRect();
                x.set((e.clientX - r.left) / r.width);
                y.set((e.clientY - r.top) / r.height);
            }}
            onMouseLeave={() => {
                x.set(0.5);
                y.set(0.5);
            }}
            style={{ rotateX, rotateY, transformPerspective: 900 }}
            className={cn('glass', hero ? 'rounded-2xl' : 'rounded-xl', className)}
        >
            {children}
        </motion.div>
    );
}
