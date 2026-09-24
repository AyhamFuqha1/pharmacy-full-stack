import type { TFunction } from "i18next";

type SystemDescriptionRule = {
  sources: readonly string[];
  descriptions: readonly string[];
};

const systemDescriptionRules: readonly SystemDescriptionRule[] = [
  { sources: ["purchase_invoice"], descriptions: ["Inventory purchase"] },
  { sources: ["sales_invoice"], descriptions: ["Sales invoice", "Amount collected at sale", "Outstanding receivable", "Sales revenue", "Cost of goods sold", "Inventory cost"] },
  { sources: ["receipt"], descriptions: ["Customer receipt", "Incoming cheque pending", "Cash received", "Customer receivable settled"] },
  { sources: ["payment"], descriptions: ["Supplier payment", "Supplier payable settled", "Outgoing cheque pending", "Cash paid"] },
  // The credit line is the user's income description; only the fixed debit label is safe.
  { sources: ["other_income"], descriptions: ["Income received"] },
  { sources: ["cheque_clear"], descriptions: ["Cheque clear", "Incoming cheque cleared to bank", "Cheque receivable cleared", "Cheque payable cleared", "Outgoing cheque cleared from bank"] },
  { sources: ["cheque_bounce"], descriptions: ["Cheque bounce", "Customer receivable restored", "Incoming cheque bounced", "Outgoing cheque liability reversed", "Supplier payable restored"] },
  { sources: ["purchase_return"], descriptions: ["Purchase return", "Accounts payable reduction", "Inventory decrease"] },
  { sources: ["sales_return"], descriptions: ["Sales return", "Sales revenue reversal", "Inventory restoration", "COGS reversal", "Cash refund", "Customer balance reversal"] },
  { sources: ["expense"], descriptions: ["Operating expense", "Cash or bank reduction"] },
  { sources: ["sales_invoice_reversal"], descriptions: ["Sales invoice cancellation"] },
  { sources: ["expense_reversal"], descriptions: ["Expense cancellation"] },
  { sources: ["receipt_reversal"], descriptions: ["Receipt voucher cancellation"] },
  { sources: ["payment_reversal"], descriptions: ["Payment voucher cancellation"] },
  { sources: ["opening_balance"], descriptions: ["Opening balances"] },
  { sources: ["stock_adjustment", "stock_count"], descriptions: ["Inventory adjustment"] },
];

const seededSystemAccounts: Readonly<Record<string, string>> = {
  "1000": "Assets",
  "1100": "Cash",
  "1200": "Bank",
  "1300": "Accounts Receivable",
  "1400": "Inventory Asset",
  "1500": "Cheques Receivable",
  "2000": "Liabilities",
  "2100": "Accounts Payable",
  "2200": "Cheques Payable",
  "3000": "Equity",
  "4000": "Revenue",
  "4100": "Sales Revenue",
  "4200": "Other Income",
  "5000": "Expenses",
  "5100": "Cost of Goods Sold",
  "5200": "Operating Expenses",
  "5300": "Inventory Adjustments",
};

export function financialDescription(
  sourceType: string,
  description: string | null | undefined,
  t: TFunction,
): string | null | undefined {
  if (!description) return description;
  const isFixedSystemText = systemDescriptionRules.some(
    (rule) => rule.sources.includes(sourceType) && rule.descriptions.includes(description),
  );
  return isFixedSystemText ? t(description) : description;
}

export function financialAccountName(code: string, storedName: string, t: TFunction): string {
  const seededName = seededSystemAccounts[code];
  return seededName === storedName ? t(seededName) : storedName;
}
