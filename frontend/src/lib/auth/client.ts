// Browser client for the staff authentication endpoints (/api/auth). The
// session lives in an HttpOnly cookie; nothing auth-related is kept in
// localStorage, and server functions read the same cookie.
import { createAuthClient } from "better-auth/react";
import { twoFactorClient } from "better-auth/client/plugins";

export const authClient = createAuthClient({
  basePath: "/api/auth",
  plugins: [twoFactorClient()],
});
