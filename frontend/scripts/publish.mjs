// Copies the built pages into the Laravel application: hashed assets and
// public files to public/, the prerendered shell to resources/web/shell.html.
import { cpSync, mkdirSync, rmSync } from "node:fs";
import path from "node:path";

const root = path.resolve(import.meta.dirname, "..");
const built = path.join(root, "dist/client");
const app = path.resolve(root, "..");

rmSync(path.join(app, "public/assets"), { recursive: true, force: true });
cpSync(path.join(built, "assets"), path.join(app, "public/assets"), { recursive: true });
for (const file of ["favicon.png", "robots.txt"]) {
  cpSync(path.join(built, file), path.join(app, "public", file));
}
mkdirSync(path.join(app, "resources/web"), { recursive: true });
cpSync(path.join(built, "_shell.html"), path.join(app, "resources/web/shell.html"));
console.log("Published to public/assets and resources/web/shell.html");
