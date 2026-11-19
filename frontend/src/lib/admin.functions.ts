// The Admin Portal endpoints (served by Laravel under /api/fn/). Every one
// needs a signed-in staff session and checks the caller's role on the server.
import { serverFn } from "./server-fn";

export const getSession = serverFn("getSession", "GET");
export const recordSignIn = serverFn("recordSignIn", "POST");
export const listUsers = serverFn("listUsers", "GET");
export const createUser = serverFn("createUser", "POST");
export const setUserEnabled = serverFn("setUserEnabled", "POST");
export const sendUserAccountEmail = serverFn("sendUserAccountEmail", "POST");
export const getDashboard = serverFn("getDashboard", "GET");
export const uploadWorkbook = serverFn("uploadWorkbook", "POST");
export const listVersions = serverFn("listVersions", "GET");
export const getVersionDetail = serverFn("getVersionDetail", "POST");
export const getPublishedData = serverFn("getPublishedData", "POST");
export const publishVersionFn = serverFn("publishVersionFn", "POST");
export const getAdminSettings = serverFn("getAdminSettings", "GET");
export const saveSetting = serverFn("saveSetting", "POST");
export const listPromptVersions = serverFn("listPromptVersions", "GET");
export const savePromptVersion = serverFn("savePromptVersion", "POST");
export const activatePromptVersion = serverFn("activatePromptVersion", "POST");
export const getAuditLog = serverFn("getAuditLog", "GET");
export const listCalculatorSubmissions = serverFn("listCalculatorSubmissions", "GET");
export const getCalculatorSubmission = serverFn("getCalculatorSubmission", "POST");
export const retryMakeDelivery = serverFn("retryMakeDelivery", "POST");
export const listSalesRecipients = serverFn("listSalesRecipients", "GET");
export const addSalesRecipient = serverFn("addSalesRecipient", "POST");
export const removeSalesRecipient = serverFn("removeSalesRecipient", "POST");
export const getSubmissionReportUrl = serverFn("getSubmissionReportUrl", "POST");
export const regenerateSubmissionReport = serverFn("regenerateSubmissionReport", "POST");
export const resendSalesNotification = serverFn("resendSalesNotification", "POST");
export const getAiQuotaOverview = serverFn("getAiQuotaOverview", "GET");
export const updateAiQuotaSettings = serverFn("updateAiQuotaSettings", "POST");
