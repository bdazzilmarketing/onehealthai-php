// The public calculator endpoints (served by Laravel under /api/fn/).
import { serverFn } from "./server-fn";

export const getCalculatorCatalog = serverFn("getCalculatorCatalog", "GET");
export const calculateEstimate = serverFn("calculateEstimate", "POST");
