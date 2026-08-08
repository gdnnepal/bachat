import { useMemo, useState } from 'react';
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, Download, FileSpreadsheet, Printer, Search } from 'lucide-react';

import Button from './Button.jsx';
import Spinner from './Spinner.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { formatNPR, formatNumber } from '../../utils/currency.js';

/**
 * Data table with search, sort, pagination and print/export controls.
 *
 * Column shape mirrors the report envelope the API returns
 * (`{ key, label, type }`), so a report payload can be handed straight in:
 *
 *   { key, label, type: 'text' | 'money' | 'number' | 'date' | 'status',
 *     align?, sortable?, render?(value, row, index), className? }
 *
 * Searching and sorting run on the full row set client-side. For server-driven
 * paging, pass `serverPaged` and drive `page`/`onPageChange` from the caller.
 */

const ALIGN = { left: 'text-left', right: 'text-right', center: 'text-center' };

function defaultAlign(type) {
  return type === 'money' || type === 'number' ? 'right' : 'left';
}

/** Renders a cell value according to its column type. */
function formatCell(value, column, locale) {
  if (value === null || value === undefined || value === '') return '—';

  switch (column.type) {
    case 'money':
      return formatNPR(value, locale);
    case 'number':
      return formatNumber(value, locale);
    default:
      return String(value);
  }
}

/** Flattens a row to a searchable string so search covers every visible column. */
function rowText(row, columns) {
  return columns
    .map((column) => {
      const value = row[column.key];
      return value === null || value === undefined ? '' : String(value);
    })
    .join(' ')
    .toLowerCase();
}

function compareValues(a, b, type) {
  if (a === null || a === undefined) return -1;
  if (b === null || b === undefined) return 1;

  if (type === 'money' || type === 'number') {
    return Number(a) - Number(b);
  }

  return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
}

export default function Table({
  columns = [],
  rows = [],
  rowKey = 'id',
  loading = false,
  emptyMessage,
  title,
  searchable = true,
  searchPlaceholder,
  pageSize = 20,
  paginated = true,
  totals = null,
  totalsLabel,
  onRowClick,
  rowClassName,
  toolbar,
  onExportExcel,
  onExportPdf,
  printable = false,
  dense = false,
  className = '',
}) {
  const { t, locale } = useI18n();

  const [query, setQuery] = useState('');
  const [sort, setSort] = useState({ key: null, direction: 'asc' });
  const [page, setPage] = useState(1);

  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase();
    if (!needle) return rows;

    return rows.filter((row) => rowText(row, columns).includes(needle));
  }, [rows, columns, query]);

  const sorted = useMemo(() => {
    if (!sort.key) return filtered;

    const column = columns.find((item) => item.key === sort.key);
    const factor = sort.direction === 'desc' ? -1 : 1;

    // Copy first — Array.prototype.sort mutates, and `rows` is the caller's data.
    return [...filtered].sort((a, b) => compareValues(a[sort.key], b[sort.key], column?.type) * factor);
  }, [filtered, sort, columns]);

  const totalPages = paginated ? Math.max(1, Math.ceil(sorted.length / pageSize)) : 1;
  const currentPage = Math.min(page, totalPages);

  const visible = useMemo(() => {
    if (!paginated) return sorted;

    const start = (currentPage - 1) * pageSize;
    return sorted.slice(start, start + pageSize);
  }, [sorted, paginated, currentPage, pageSize]);

  const toggleSort = (column) => {
    if (column.sortable === false) return;

    setSort((current) => {
      if (current.key !== column.key) return { key: column.key, direction: 'asc' };
      if (current.direction === 'asc') return { key: column.key, direction: 'desc' };
      return { key: null, direction: 'asc' };
    });
  };

  const onSearch = (value) => {
    setQuery(value);
    setPage(1); // A narrower result set can leave the old page out of range.
  };

  const showToolbar = searchable || toolbar || onExportExcel || onExportPdf || printable || title;
  const cellPadding = dense ? 'px-3 py-1.5' : 'px-3 py-2.5';

  return (
    <div className={`card overflow-hidden ${className}`}>
      {showToolbar && (
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
          <div className="flex items-center gap-3">
            {title && <h2 className="text-sm font-semibold text-slate-800">{title}</h2>}

            {searchable && (
              <div className="relative no-print">
                <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                  type="search"
                  value={query}
                  onChange={(event) => onSearch(event.target.value)}
                  placeholder={searchPlaceholder || t('table.search')}
                  aria-label={searchPlaceholder || t('table.search')}
                  className="form-input w-56 pl-8"
                />
              </div>
            )}
          </div>

          <div className="flex items-center gap-2 no-print">
            {toolbar}

            {onExportExcel && (
              <Button variant="secondary" size="sm" icon={FileSpreadsheet} onClick={onExportExcel}>
                {t('button.export_excel')}
              </Button>
            )}

            {onExportPdf && (
              <Button variant="secondary" size="sm" icon={Download} onClick={onExportPdf}>
                {t('button.export_pdf')}
              </Button>
            )}

            {printable && (
              <Button variant="secondary" size="sm" icon={Printer} onClick={() => window.print()}>
                {t('button.print')}
              </Button>
            )}
          </div>
        </div>
      )}

      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50">
            <tr>
              {columns.map((column) => {
                const align = ALIGN[column.align || defaultAlign(column.type)];
                const sortable = column.sortable !== false;
                const active = sort.key === column.key;

                return (
                  <th
                    key={column.key}
                    scope="col"
                    aria-sort={active ? (sort.direction === 'asc' ? 'ascending' : 'descending') : 'none'}
                    className={`${cellPadding} ${align} text-xs font-semibold uppercase tracking-wide text-slate-600`}
                  >
                    {sortable ? (
                      <button
                        type="button"
                        onClick={() => toggleSort(column)}
                        className={`inline-flex items-center gap-1 hover:text-slate-900 ${
                          column.align === 'right' || defaultAlign(column.type) === 'right' ? 'flex-row-reverse' : ''
                        }`}
                      >
                        {column.label}
                        {active &&
                          (sort.direction === 'asc' ? (
                            <ArrowUp className="h-3 w-3" aria-hidden="true" />
                          ) : (
                            <ArrowDown className="h-3 w-3" aria-hidden="true" />
                          ))}
                      </button>
                    ) : (
                      column.label
                    )}
                  </th>
                );
              })}
            </tr>
          </thead>

          <tbody className="divide-y divide-slate-100 bg-white">
            {loading && (
              <tr>
                <td colSpan={columns.length} className="px-3 py-10 text-center">
                  <span className="inline-flex items-center gap-2 text-slate-500">
                    <Spinner size="sm" />
                    {t('common.loading')}
                  </span>
                </td>
              </tr>
            )}

            {!loading && visible.length === 0 && (
              <tr>
                <td colSpan={columns.length} className="px-3 py-10 text-center text-slate-500">
                  {emptyMessage || t('table.no_data')}
                </td>
              </tr>
            )}

            {!loading &&
              visible.map((row, index) => (
                <tr
                  key={row[rowKey] ?? index}
                  onClick={onRowClick ? () => onRowClick(row) : undefined}
                  className={`${onRowClick ? 'cursor-pointer' : ''} hover:bg-slate-50 ${
                    typeof rowClassName === 'function' ? rowClassName(row) || '' : rowClassName || ''
                  }`}
                >
                  {columns.map((column) => {
                    const align = ALIGN[column.align || defaultAlign(column.type)];
                    const value = row[column.key];

                    return (
                      <td
                        key={column.key}
                        className={`${cellPadding} ${align} ${
                          column.type === 'money' || column.type === 'number' ? 'tabular-nums' : ''
                        } text-slate-700 ${column.className || ''}`}
                      >
                        {column.render ? column.render(value, row, index) : formatCell(value, column, locale)}
                      </td>
                    );
                  })}
                </tr>
              ))}
          </tbody>

          {totals && !loading && (
            <tfoot className="border-t-2 border-slate-300 bg-slate-50 font-semibold text-slate-900">
              <tr>
                {columns.map((column, index) => {
                  const align = ALIGN[column.align || defaultAlign(column.type)];
                  const value = totals[column.key];

                  return (
                    <td key={column.key} className={`${cellPadding} ${align} tabular-nums`}>
                      {index === 0 && value === undefined
                        ? totalsLabel || t('table.total')
                        : value === undefined
                          ? ''
                          : formatCell(value, column, locale)}
                    </td>
                  );
                })}
              </tr>
            </tfoot>
          )}
        </table>
      </div>

      {paginated && !loading && sorted.length > 0 && (
        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 px-4 py-2.5 text-xs text-slate-600 no-print">
          <span>
            {t('table.range', {
              from: (currentPage - 1) * pageSize + 1,
              to: Math.min(currentPage * pageSize, sorted.length),
              total: sorted.length,
            })}
          </span>

          <div className="flex items-center gap-1">
            <Button
              variant="ghost"
              size="sm"
              icon={ChevronLeft}
              disabled={currentPage <= 1}
              onClick={() => setPage(currentPage - 1)}
            >
              {t('table.previous')}
            </Button>

            <span className="px-2">
              {t('table.page_of', { page: currentPage, pages: totalPages })}
            </span>

            <Button
              variant="ghost"
              size="sm"
              disabled={currentPage >= totalPages}
              onClick={() => setPage(currentPage + 1)}
            >
              {t('table.next')}
              <ChevronRight className="h-4 w-4" aria-hidden="true" />
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
