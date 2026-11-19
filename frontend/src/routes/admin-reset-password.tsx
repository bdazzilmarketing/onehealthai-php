import { createFileRoute } from "@tanstack/react-router";

import { PasswordLinkPage } from "@/components/admin/PasswordLinkPage";

export const Route = createFileRoute("/admin-reset-password")({
  ssr: false,
  head: () => ({
    meta: [
      { title: "Reset Administrator Password — One Health AI Lab Calculator" },
      {
        name: "description",
        content: "Choose a new password for your One Health administrator account.",
      },
      { property: "og:title", content: "Reset Administrator Password — One Health" },
      { property: "og:description", content: "Administrator password reset." },
      { property: "og:type", content: "website" },
      { name: "twitter:card", content: "summary" },
      { name: "robots", content: "noindex, nofollow" },
    ],
  }),
  component: () => <PasswordLinkPage mode="reset" />,
});
