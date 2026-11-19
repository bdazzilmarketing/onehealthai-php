/**
 * Animated "AI / high-tech" backdrop for the public landing page.
 *
 * Deep near-black navy at the top fading into a broad electric-blue glow that
 * rises from the bottom of the viewport, with a softer bloom pooling to the
 * lower-left. Pure CSS gradients, no images.
 * Respects prefers-reduced-motion (see src/styles.css).
 */
export function TechBackground() {
  return (
    <div aria-hidden="true" className="pointer-events-none fixed inset-0 overflow-hidden">
      {/* base: near-black navy */}
      <div className="absolute inset-0 bg-[linear-gradient(to_bottom,#02040c_0%,#040a1c_35%,#061436_70%,#0a2154_100%)]" />

      {/* wide luminous floor */}
      <div className="absolute inset-x-[-20%] bottom-[-35%] h-[85vh] bg-[radial-gradient(60%_100%_at_50%_100%,#7db8ff_0%,#3f7fe0_28%,rgba(21,64,140,0.55)_55%,transparent_80%)] blur-[40px]" />

      {/* lower-left pool */}
      <div className="oh-drift-a absolute bottom-[-20%] left-[-15%] h-[70vmax] w-[70vmax] rounded-full bg-[radial-gradient(circle,rgba(120,180,255,0.42)_0%,rgba(40,100,200,0.18)_40%,transparent_70%)] blur-3xl" />

      {/* soft counter-glow to the right */}
      <div className="oh-drift-b absolute right-[-20%] bottom-[-25%] h-[55vmax] w-[55vmax] rounded-full bg-[radial-gradient(circle,rgba(90,150,255,0.22)_0%,transparent_65%)] blur-3xl" />

      {/* faint vignette at the very top */}
      <div className="absolute inset-x-0 top-0 h-[45vh] bg-[linear-gradient(to_bottom,rgba(0,0,0,0.75),transparent)]" />
    </div>
  );
}
