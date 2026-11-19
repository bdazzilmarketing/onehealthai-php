import { createFileRoute } from "@tanstack/react-router";

import { PasswordLinkPage } from "@/components/admin/PasswordLinkPage";

export const Route = createFileRoute("/admin-activate")({
  ssr: false,
  head: () => ({
    meta: [
      { title: "Activate Administrator Account — One Health AI Lab Calculator" },
      {
        name: "description",
        content: "Set a password to activate your One Health administrator account.",
      },
      { property: "og:title", content: "Activate Administrator Account — One Health" },
      { property: "og:description", content: "Administrator account activation." },
      { property: "og:type", content: "website" },
      { name: "twitter:card", content: "summary" },
      { name: "robots", content: "noindex, nofollow" },
    ],
  }),
  component: () => <PasswordLinkPage mode="activate" />,
});
