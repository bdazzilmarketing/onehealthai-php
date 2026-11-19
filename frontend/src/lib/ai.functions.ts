// The AI assistant endpoints (served by Laravel under /api/fn/).
import { serverFn } from "./server-fn";

export const getAiAvailability = serverFn("getAiAvailability", "GET");
export const sendAiMessage = serverFn("sendAiMessage", "POST");
export const getAiPicker = serverFn("getAiPicker", "POST");
export const sendAiChoice = serverFn("sendAiChoice", "POST");
export const resetAiSession = serverFn("resetAiSession", "POST");
export const finalizeAiSelection = serverFn("finalizeAiSelection", "POST");
