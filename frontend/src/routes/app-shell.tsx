import { createFileRoute, notFound } from "@tanstack/react-router";

// Build-time only: the address the page shell is prerendered at. The shell
// holds the document head and the root layout; Laravel serves it for every
// page and the browser then renders the page itself. Keeping it apart from
// the real pages means no page's own content or title ends up in the shell.
// It is not a page: opened in a browser it shows "Page not found".
export const Route = createFileRoute("/app-shell")({
  beforeLoad: () => {
    throw notFound();
  },
});
