/**
 * Rotating dotted particle sphere — canvas rendered, no images.
 *
 * Points are distributed on a sphere (Fibonacci lattice) and rotated around
 * the Y axis, with depth-based size/opacity giving a dense-rim look.
 * Respects prefers-reduced-motion (renders a single static frame).
 */
import { useEffect, useRef } from "react";

import { cn } from "@/lib/utils";

const COUNT = 1100;

export function AiOrb({ className }: { className?: string }) {
  const canvasRef = useRef<HTMLCanvasElement | null>(null);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    // Fibonacci sphere distribution, stored in spherical coords so each point
    // can drift independently over time.
    const pts: {
      lat: number;
      lon: number;
      rad: number;
      r: number;
      ph: number;
      sp: number;
      amp: number;
      tw: number;
    }[] = [];
    const golden = Math.PI * (3 - Math.sqrt(5));
    for (let i = 0; i < COUNT; i++) {
      const y = 1 - (i / (COUNT - 1)) * 2;
      pts.push({
        lat: Math.asin(Math.max(-1, Math.min(1, y))),
        lon: golden * i,
        rad: 0.94 + Math.random() * 0.09,
        r: 0.4 + Math.random() * 0.9,
        ph: Math.random() * Math.PI * 2,
        sp: 0.4 + Math.random() * 0.9,
        amp: 0.012 + Math.random() * 0.035,
        tw: 0.5 + Math.random() * 1.4,
      });
    }

    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    let raf = 0;
    let angle = 0;
    let t = 0;
    let dpr = 1;
    let size = 0;

    const resize = () => {
      const rect = canvas.getBoundingClientRect();
      dpr = Math.min(window.devicePixelRatio || 1, 2);
      size = Math.max(1, Math.min(rect.width, rect.height));
      canvas.width = Math.round(size * dpr);
      canvas.height = Math.round(size * dpr);
    };
    resize();

    const draw = () => {
      const s = size * dpr;
      const c = s / 2;
      const R = s * 0.42;
      ctx.clearRect(0, 0, s, s);
      ctx.globalCompositeOperation = "lighter";

      // slight tilt so poles read as caps, like the reference
      const tilt = -0.32;
      const ct = Math.cos(tilt);
      const st = Math.sin(tilt);

      for (const p of pts) {
        // per-particle wander around its home position
        const w = t * p.sp + p.ph;
        const lat = p.lat + Math.sin(w) * p.amp;
        const lon = p.lon + angle + Math.cos(w * 0.83) * p.amp * 1.6;
        const rr = p.rad * (1 + Math.sin(w * 1.3) * 0.03);

        const cl = Math.cos(lat) * rr;
        const x1 = Math.cos(lon) * cl;
        const z1 = Math.sin(lon) * cl;
        const y0 = Math.sin(lat) * rr;
        const y1 = y0 * ct - z1 * st;
        const z2 = y0 * st + z1 * ct;

        const depth = (z2 + 1) / 2; // 0 back → 1 front
        const persp = 0.72 + depth * 0.4;
        const px = c + x1 * R * persp;
        const py = c + y1 * R * persp;

        const twinkle = 0.75 + 0.25 * Math.sin(t * p.tw + p.ph * 2);
        const alpha = (0.12 + depth * 0.8) * 0.9 * twinkle;
        const rad = p.r * dpr * (0.55 + depth * 0.85);

        ctx.globalAlpha = alpha;
        ctx.fillStyle = depth > 0.75 ? "#eaf4ff" : "#b9dcff";
        ctx.beginPath();
        ctx.arc(px, py, rad, 0, Math.PI * 2);
        ctx.fill();
      }
      ctx.globalAlpha = 1;
      ctx.globalCompositeOperation = "source-over";
    };

    const loop = () => {
      angle += 0.0022;
      t += 0.016;
      draw();
      raf = requestAnimationFrame(loop);
    };

    if (reduced) draw();
    else raf = requestAnimationFrame(loop);

    const onResize = () => {
      resize();
      draw();
    };
    window.addEventListener("resize", onResize);

    return () => {
      cancelAnimationFrame(raf);
      window.removeEventListener("resize", onResize);
    };
  }, []);

  return (
    <div aria-hidden="true" className={cn("relative aspect-square", className)}>
      {/* soft bloom behind the particle field */}
      <div className="absolute -inset-[20%] rounded-full bg-[radial-gradient(circle,color-mix(in_oklab,#7fc0ff_22%,transparent)_0%,transparent_65%)] blur-2xl" />
      <canvas ref={canvasRef} className="relative h-full w-full" />
    </div>
  );
}
