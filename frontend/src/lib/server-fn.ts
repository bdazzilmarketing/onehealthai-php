// Calls a server endpoint under /api/fn/. The session and visitor cookies are
// HttpOnly and travel with the request; nothing is kept in the browser.
type Options<TInput> = { data?: TInput; signal?: AbortSignal };

export type ServerFn<TInput, TOutput> = (options?: Options<TInput>) => Promise<TOutput>;

async function errorMessage(response: Response): Promise<string> {
  const text = await response.text().catch(() => "");
  try {
    const body = JSON.parse(text) as { message?: unknown };
    if (typeof body.message === "string" && body.message) return body.message;
  } catch {
    // not JSON: use the text itself
  }
  return text.trim().slice(0, 300) || `Request failed (${response.status})`;
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function serverFn<TInput = any, TOutput = any>(
  name: string,
  method: "GET" | "POST",
): ServerFn<TInput, TOutput> {
  return async (options) => {
    const response = await fetch(`/api/fn/${name}`, {
      method,
      credentials: "same-origin",
      headers:
        method === "POST"
          ? { accept: "application/json", "content-type": "application/json" }
          : { accept: "application/json" },
      ...(method === "POST" ? { body: JSON.stringify(options?.data ?? {}) } : {}),
      ...(options?.signal ? { signal: options.signal } : {}),
    });
    if (!response.ok) throw new Error(await errorMessage(response));
    return (await response.json()) as TOutput;
  };
}
