import { createFileRoute, Link } from "@tanstack/react-router";
import { useEffect, useState } from "react";

import oneHealthLogoUrl from "@/assets/onehealth-logo.png";
import { AiOrb } from "@/components/public/ai-orb";
import { Arrow, DisplayHeading, PublicPage, Shell } from "@/components/public/ui";


export const Route = createFileRoute("/")({
  head: () => ({
    meta: [
      { title: "One Health AI Lab Calculator — Laboratory Setup Estimator" },
      {
        name: "description",
        content:
          "Laboratory setup cost estimation for Core Lab, POCT, Molecular, Microbiology and Genetics laboratories.",
      },
      { property: "og:title", content: "One Health AI Lab Calculator" },
      {
        property: "og:description",
        content: "Laboratory setup cost estimation platform.",
      },
      { property: "og:type", content: "website" },
      { name: "twitter:card", content: "summary_large_image" },
    ],
  }),
  component: Index,
});

const MODES = [
  { label: "AI Mode", mode: "ai" as const, primary: true },
  { label: "Manual Mode", mode: "manual" as const, primary: false },
];

const CLIP = "polygon(0 0, calc(100% - 0.7rem) 0, 100% 0.7rem, 100% 100%, 0 100%)";

function Index() {
  useEffect(() => {
    // Account links that fall back to the homepage are forwarded to activation unchanged.
    const hash = window.location.hash;
    if (/(^#|&)(type=(invite|recovery)|error_code=)/.test(hash)) {
      window.location.replace(`/admin-activate${hash}`);
    }
  }, []);
  return (
    <PublicPage
      tone="blue"
      showBack={false}
      showHeader={false}
      className="relative isolate bg-[#02040c]"
    >
      <header className="relative z-20">
        <Shell className="pt-5 sm:pt-6">
          <Link
            to="/"
            className="inline-block focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white"
          >
            <img
              src={oneHealthLogoUrl}
              alt="One Health"
              className="h-8 w-auto sm:h-9"
              width={1160}
              height={617}
            />
          </Link>
        </Shell>
      </header>
      <main className="relative z-10">
        <Shell className="flex flex-col items-center pt-4 pb-10 sm:pt-6">
          <DisplayHeading className="mx-auto max-w-[24ch] text-center text-[clamp(2.4rem,5.6vw,4.25rem)] leading-[1.05] text-white">
            Design Your Lab. Smarter.
          </DisplayHeading>

          <AiOrb className="mt-2 w-[clamp(14rem,32vw,22rem)]" />

          <div className="-mt-4 flex flex-wrap items-center justify-center gap-3 sm:-mt-6">
            {MODES.map((m) => (
              <Link
                key={m.mode}
                to={m.mode === "ai" ? "/ai" : "/calculator"}
                search={{}}
                style={{ clipPath: CLIP }}
                className={
                  m.primary
                    ? "group inline-flex items-center gap-2 bg-white px-6 py-3 text-[0.8125rem] font-medium text-oh-blue-deep transition-colors duration-200 hover:bg-white/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                    : "group inline-flex items-center gap-2 border border-white/25 bg-white/[0.06] px-6 py-3 text-[0.8125rem] font-medium text-white backdrop-blur-sm transition-colors duration-200 hover:border-white/50 hover:bg-white/[0.12] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                }
              >
                {m.label}
                <Arrow className="transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 motion-reduce:transition-none" />
              </Link>
            ))}
          </div>

          <GetInTouch />
        </Shell>
      </main>
    </PublicPage>
  );
}

const FIELD_CLIP =
  "polygon(0 0, calc(100% - 0.55rem) 0, 100% 0.55rem, 100% 100%, 0 100%)";

const fieldInput =
  "w-full border border-white/15 bg-white/[0.05] px-4 py-3 text-sm text-white placeholder:text-white/40 backdrop-blur-sm transition-colors duration-200 focus:border-white/45 focus:outline-none motion-reduce:transition-none";

function GetInTouch() {
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!name.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) return;
    setSent(true);
  }

  return (
    <section className="mx-auto w-full max-w-3xl px-1 pt-12 pb-20 text-center">
      <div
        aria-hidden="true"
        className="mx-auto h-px w-40 bg-gradient-to-r from-transparent via-white/50 to-transparent"
      />
      <h2 className="mt-10 font-display text-[clamp(2rem,4.5vw,3.25rem)] font-medium leading-[1.08] tracking-[-0.02em] text-white">
        Get in touch
      </h2>
      <p className="mx-auto mt-4 max-w-md text-[0.9375rem] leading-relaxed text-white/55">
        Let's connect. Leave your details and we'll get back to you.
      </p>

      {sent ? (
        <p
          style={{ clipPath: CLIP }}
          className="mx-auto mt-10 inline-block border border-white/20 bg-white/[0.07] px-6 py-3.5 text-[0.8125rem] font-medium text-white backdrop-blur-sm"
        >
          Thank you — we'll be in touch shortly.
        </p>
      ) : (
        <form
          onSubmit={handleSubmit}
          style={{ clipPath: PANEL_CONTACT_CLIP }}
          className="mx-auto mt-10 flex max-w-2xl flex-col gap-3 border border-white/15 bg-white/[0.04] p-3 backdrop-blur-md sm:flex-row sm:items-center"
        >
          <input
            type="text"
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Name"
            aria-label="Name"
            required
            maxLength={100}
            style={{ clipPath: FIELD_CLIP }}
            className={fieldInput}
          />
          <input
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            placeholder="Email"
            aria-label="Email"
            required
            maxLength={255}
            style={{ clipPath: FIELD_CLIP }}
            className={fieldInput}
          />
          <button
            type="submit"
            style={{ clipPath: CLIP }}
            className="group inline-flex shrink-0 items-center justify-center gap-2 bg-white px-6 py-3 text-[0.8125rem] font-medium text-oh-blue-deep transition-colors duration-200 hover:bg-white/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white motion-reduce:transition-none"
          >
            Contact us
            <Arrow className="transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 motion-reduce:transition-none" />
          </button>
        </form>
      )}
    </section>
  );
}

const PANEL_CONTACT_CLIP =
  "polygon(0 0, calc(100% - 1.15rem) 0, 100% 1.15rem, 100% 100%, 0 100%)";
