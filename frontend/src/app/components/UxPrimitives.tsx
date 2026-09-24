import { Info, LoaderCircle, Search, X } from "lucide-react";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { useTranslation } from "react-i18next";
import { createPortal } from "react-dom";

function useUi() {
  const { t } = useTranslation();
  return (value: string | undefined) =>
    value ? t(value, { defaultValue: value }) : value;
}
export function PageHeader({
  title,
  description,
  action,
}: {
  title: string;
  description: string;
  action?: ReactNode;
}) {
  const ui = useUi();
  return (
    <header className="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 className="text-3xl font-bold text-gray-900">{ui(title)}</h1>
        <p className="mt-1 max-w-3xl text-gray-600">{ui(description)}</p>
      </div>
      {action}
    </header>
  );
}
export function FieldHelp({ text }: { text: string }) {
  const ui = useUi(),
    translated = ui(text);
  return (
    <span className="relative group inline-flex">
      <button
        type="button"
        aria-label={translated}
        title={translated}
        className="text-gray-400 hover:text-teal-700 focus-visible:outline focus-visible:outline-2 rounded"
      >
        <Info size={15} />
      </button>
      <span
        role="tooltip"
        className="pointer-events-none absolute z-30 hidden group-hover:block group-focus-within:block bottom-full start-1/2 -translate-x-1/2 mb-2 w-64 rounded-lg bg-gray-900 p-2 text-xs font-normal text-white shadow-lg"
      >
        {translated}
      </span>
    </span>
  );
}
export function Field({
  label,
  help,
  required,
  children,
  hint,
  error,
}: {
  label: string;
  help?: string;
  required?: boolean;
  children: ReactNode;
  hint?: string;
  error?: string;
}) {
  const ui = useUi();
  return (
    <label className="block">
      {(label || required || help) && (
        <span className="mb-2 flex items-center gap-1 text-sm font-semibold text-gray-700">
          {ui(label)}
          {required && (
            <span className="text-red-600" aria-label={ui("required")}>
              *
            </span>
          )}
          {help && <FieldHelp text={help} />}
        </span>
      )}
      {children}
      {hint && (
        <span className="mt-1 block text-xs text-gray-500">{ui(hint)}</span>
      )}
      {error && (
        <span className="mt-1 block text-xs text-red-600">{ui(error)}</span>
      )}
    </label>
  );
}
export type SelectOption = { id: number | string; label: string };
export function EntitySelect({
  label,
  value,
  onChange,
  options,
  allLabel = "Select an option",
  help,
  required,
  searchable = true,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  options: SelectOption[];
  allLabel?: string;
  help?: string;
  required?: boolean;
  searchable?: boolean;
}) {
  const ui = useUi();
  return (
    <Field label={label} help={help} required={required}>
      <div className="relative">
        {searchable && (
          <Search
            aria-hidden
            className="absolute start-3 top-3 text-gray-400"
            size={17}
          />
        )}
        <select
          className={`input ${searchable ? "ps-9" : ""}`}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          required={required}
        >
          <option value="">{ui(allLabel)}</option>
          {options.map((x) => (
            <option data-i18n-skip key={x.id} value={x.id}>
              {x.label}
            </option>
          ))}
        </select>
      </div>
    </Field>
  );
}
export function RemoteEntitySelect({
  label,
  value,
  onChange,
  load,
  placeholder = "Search by code or name",
  help,
  required,
  onSelect,
  selectedOption,
  reloadKey,
  disabled = false,
  error: fieldError,
  inputClassName = "input",
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  load: (
    search: string,
    page: number,
    signal: AbortSignal,
  ) => Promise<{ options: SelectOption[]; hasMore: boolean }>;
  placeholder?: string;
  help?: string;
  required?: boolean;
  onSelect?: (option: SelectOption) => void;
  selectedOption?: SelectOption | null;
  reloadKey?: string | number;
  disabled?: boolean;
  error?: string;
  inputClassName?: string;
}) {
  const ui = useUi();
  const [search, setSearch] = useState(""),
    [options, setOptions] = useState<SelectOption[]>([]),
    [page, setPage] = useState(1),
    [hasMore, setHasMore] = useState(false),
    [open, setOpen] = useState(false),
    [loading, setLoading] = useState(false),
    [resolved, setResolved] = useState(false),
    [error, setError] = useState(""),
    [selected, setSelected] = useState<SelectOption | null>(null),
    request = useRef(0),
    [activeIndex, setActiveIndex] = useState(-1),
    loader = useRef(load),
    container = useRef<HTMLDivElement>(null),
    anchor = useRef<HTMLInputElement>(null),
    panel = useRef<HTMLDivElement>(null),
    [position, setPosition] = useState<{
      top: number;
      left: number;
      width: number;
    } | null>(null);
  loader.current = load;
  useEffect(() => {
    if (!open) return;
    const closeOutside = (event: PointerEvent) => {
      const target = event.target as Node;
      if (
        !container.current?.contains(target) &&
        !panel.current?.contains(target)
      )
        setOpen(false);
    };
    document.addEventListener("pointerdown", closeOutside);
    return () => document.removeEventListener("pointerdown", closeOutside);
  }, [open]);
  useEffect(() => {
    if (!open || selected) return;
    const positionPanel = () => {
      const input = anchor.current;
      if (!input) return;
      const rect = input.getBoundingClientRect();
      if (
        rect.bottom < 0 ||
        rect.top > window.innerHeight ||
        rect.right < 0 ||
        rect.left > window.innerWidth
      ) {
        setOpen(false);
        return;
      }
      const margin = 12,
        gap = 4,
        width = Math.min(
          Math.max(rect.width, 320),
          window.innerWidth - margin * 2,
        ),
        rtl = document.documentElement.dir === "rtl",
        preferredLeft = rtl ? rect.right - width : rect.left,
        left = Math.min(
          Math.max(preferredLeft, margin),
          window.innerWidth - width - margin,
        ),
        below = window.innerHeight - rect.bottom - gap - margin,
        above = rect.top - gap - margin,
        panelHeight = Math.min(280, panel.current?.offsetHeight || 280),
        top =
          below < Math.min(panelHeight, 160) && above > below
            ? Math.max(margin, rect.top - panelHeight - gap)
            : rect.bottom + gap;
      setPosition({ top, left, width });
    };
    positionPanel();
    window.addEventListener("resize", positionPanel);
    window.addEventListener("scroll", positionPanel, true);
    return () => {
      window.removeEventListener("resize", positionPanel);
      window.removeEventListener("scroll", positionPanel, true);
    };
  }, [open, selected]);
  useEffect(() => {
    if (!value) setSelected(null);
    else if (selectedOption && String(selectedOption.id) === value)
      setSelected(selectedOption);
  }, [value, selectedOption]);
  useEffect(() => {
    const c = new AbortController(),
      current = ++request.current,
      t = setTimeout(() => {
        setLoading(true);
        setError("");
        loader
          .current(search, 1, c.signal)
          .then((r) => {
            if (current === request.current) {
              setOptions(r.options);
              setHasMore(r.hasMore);
              setPage(1);
              setActiveIndex(r.options.length ? 0 : -1);
              setResolved(true);
            }
          })
          .catch((v) => {
            if (
              current === request.current &&
              !(v instanceof DOMException && v.name === "AbortError")
            ) {
              setResolved(true);
              setError(
                ui(
                  "Options could not be loaded. Check your access or connection and try again.",
                ),
              );
            }
          })
          .finally(() => current === request.current && setLoading(false));
      }, 300);
    setResolved(false);
    setLoading(true);
    return () => {
      clearTimeout(t);
      c.abort();
    };
  }, [search, reloadKey]);
  async function more() {
    const c = new AbortController(),
      current = request.current;
    setLoading(true);
    try {
      const r = await loader.current(search, page + 1, c.signal);
      if (current !== request.current) return;
      setOptions((x) => [...x, ...r.options]);
      setHasMore(r.hasMore);
      setPage((x) => x + 1);
    } catch {
      if (current === request.current)
        setError(ui("More options could not be loaded."));
    } finally {
      if (current === request.current) setLoading(false);
    }
  }
  return (
    <Field label={label} help={help} required={required} error={fieldError}>
      <div
        ref={container}
        className="relative w-full min-w-0"
        dir={document.documentElement.dir || undefined}
      >
        {selected && value ? (
          <div
            className={`${inputClassName} flex w-full min-w-0 items-center justify-between`}
          >
            <span className="truncate">{selected.label}</span>
            <button
              type="button"
              aria-label={ui("Clear selection")}
              disabled={disabled}
              onClick={() => {
                setSelected(null);
                onChange("");
                setSearch("");
                setOpen(true);
              }}
            >
              <X size={17} />
            </button>
          </div>
        ) : (
          <>
            <Search
              className="absolute start-3 top-3 text-gray-400"
              size={17}
            />
            <input
              ref={anchor}
              className={`${inputClassName} w-full min-w-0 ps-9`}
              value={search}
              placeholder={ui(placeholder)}
              onFocus={() => setOpen(true)}
              onChange={(e) => {
                setSearch(e.target.value);
                setOpen(true);
              }}
              aria-autocomplete="list"
              role="combobox"
              aria-expanded={open}
              disabled={disabled}
              onKeyDown={(event) => {
                if (event.key === "Escape") {
                  setOpen(false);
                  return;
                }
                if (event.key === "ArrowDown") {
                  event.preventDefault();
                  setOpen(true);
                  setActiveIndex((index) =>
                    Math.min(index + 1, options.length - 1),
                  );
                  return;
                }
                if (event.key === "ArrowUp") {
                  event.preventDefault();
                  setActiveIndex((index) => Math.max(index - 1, 0));
                  return;
                }
                if (
                  event.key === "Enter" &&
                  open &&
                  activeIndex >= 0 &&
                  options[activeIndex]
                ) {
                  event.preventDefault();
                  const option = options[activeIndex];
                  setSelected(option);
                  onChange(String(option.id));
                  onSelect?.(option);
                  setOpen(false);
                }
              }}
              required={required && !value}
            />
          </>
        )}
        {open &&
          !selected &&
          position &&
          createPortal(
            <div
              ref={panel}
              className="fixed z-50 max-h-[280px] overflow-y-auto rounded-xl border border-gray-200/70 bg-white/90 shadow-lg backdrop-blur-md"
              style={{
                top: position.top,
                left: position.left,
                width: position.width,
                maxWidth: "calc(100vw - 24px)",
              }}
              dir={document.documentElement.dir || undefined}
              role="listbox"
            >
              {(!resolved || loading) && options.length === 0 ? (
                <div className="flex gap-2 p-4 text-sm text-gray-500">
                  <LoaderCircle className="animate-spin" size={17} />
                  {ui("Loading options…")}
                </div>
              ) : error ? (
                <div className="p-4 text-sm text-red-700">{error}</div>
              ) : options.length === 0 ? (
                <div className="p-4 text-sm text-gray-500">
                  {ui("No matching options found. Try another name or code.")}
                </div>
              ) : (
                options.map((option, index) => (
                  <button
                    type="button"
                    role="option"
                    className={`block w-full p-3 text-start transition-colors hover:bg-teal-50/70 ${index === activeIndex ? "bg-teal-50/70" : ""}`}
                    aria-selected={index === activeIndex}
                    onMouseEnter={() => setActiveIndex(index)}
                    key={`${option.id}-${option.label}`}
                    onClick={() => {
                      setSelected(option);
                      onChange(String(option.id));
                      onSelect?.(option);
                      setOpen(false);
                    }}
                  >
                    <span data-i18n-skip>{option.label}</span>
                  </button>
                ))
              )}
              {hasMore && (
                <button
                  type="button"
                  className="w-full border-t p-3 text-sm text-teal-700"
                  disabled={loading}
                  onClick={() => void more()}
                >
                  {loading ? ui("Loading…") : ui("Load more results")}
                </button>
              )}
            </div>,
            document.body,
          )}
      </div>
    </Field>
  );
}
export function FormSection({
  title,
  description,
  children,
}: {
  title: string;
  description?: string;
  children: ReactNode;
}) {
  const ui = useUi();
  return (
    <fieldset className="rounded-2xl border bg-white p-5">
      <legend className="px-2 text-lg font-bold">{ui(title)}</legend>
      {description && (
        <p className="mb-4 text-sm text-gray-600">{ui(description)}</p>
      )}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{children}</div>
    </fieldset>
  );
}
export function EmptyState({
  title,
  description,
  action,
}: {
  title: string;
  description: string;
  action?: ReactNode;
}) {
  const ui = useUi();
  return (
    <div className="p-10 text-center">
      <h3 className="font-semibold text-gray-800">{ui(title)}</h3>
      <p className="mx-auto mt-1 max-w-lg text-sm text-gray-500">
        {ui(description)}
      </p>
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}
export const movementOptions = [
  ["", "All movements"],
  ["OPENING_IN", "Opening Stock"],
  ["PURCHASE_IN", "Purchase"],
  ["PURCHASE_RETURN_OUT", "Purchase Return"],
  ["SALE_OUT", "Sale"],
  ["SALES_RETURN_IN", "Sales Return"],
  ["PRESCRIPTION_DISPENSE_OUT", "Prescription Dispense"],
  ["ADJUSTMENT_IN", "Stock Adjustment In"],
  ["ADJUSTMENT_OUT", "Stock Adjustment Out"],
  ["STOCK_COUNT_IN", "Stock Count"],
  ["TRANSFER_IN", "Transfer In"],
  ["TRANSFER_OUT", "Transfer Out"],
];
