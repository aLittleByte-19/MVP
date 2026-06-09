import styles from "./DataTable.module.css";

type DataTableColumn<TRow> = {
  key: string;
  header: string;
  render: (row: TRow) => React.ReactNode;
};

type DataTableProps<TRow> = {
  columns: DataTableColumn<TRow>[];
  rows: TRow[];
  getRowKey: (row: TRow) => string;
  /** Optional per-row status tone, renders a colored left strip (e.g. "needs-review", "confirmed", "sent"). */
  getRowTone?: (row: TRow) => string | undefined;
};

export function DataTable<TRow>({ columns, rows, getRowKey, getRowTone }: DataTableProps<TRow>) {
  return (
    <div className={styles.tableWrapper}>
      <table className={styles.table}>
        <thead>
          <tr>
            {columns.map((column) => (
              <th key={column.key} scope="col">
                {column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={getRowKey(row)} data-tone={getRowTone?.(row)}>
              {columns.map((column) => (
                <td key={column.key}>{column.render(row)}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
