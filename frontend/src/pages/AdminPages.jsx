// Replace the existing AllQueues function in AdminPages.jsx

export function AllQueues() {
  const toast = useToast();

  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [date, setDate] = useState('');
  const [page, setPage] = useState(1);

  const limit = 20;

  const qs = new URLSearchParams({
    page: String(page),
    limit: String(limit)
  });

  if (status) qs.set('status', status);
  if (search.trim()) qs.set('search', search.trim());
  if (date) qs.set('date', date);

  // Automatically refresh every 5 seconds.
  // Empty date means transactions from ALL dates.
  const { data, loading, error, refresh } = useRemote(
    `/queues?${qs.toString()}`,
    5000
  );

  const tickets = data?.items || [];
  const total = Number(data?.total || 0);
  const totalPages = Math.max(1, Math.ceil(total / limit));

  const changeStatus = (value) => {
    setStatus(value);
    setPage(1);
  };

  const changeSearch = (value) => {
    setSearch(value);
    setPage(1);
  };

  const changeDate = (value) => {
    setDate(value);
    setPage(1);
  };

  const clearFilters = () => {
    setStatus('');
    setSearch('');
    setDate('');
    setPage(1);
  };

  const priority = async (q, value) => {
    try {
      await request(
        'patch',
        `/queues/${q.id}/priority`,
        {
          priority_level: Number(value)
        }
      );

      toast('Ticket priority updated.');
      refresh();

    } catch (err) {
      toast(getError(err), 'error');
    }
  };

  return (
    <>
      <PageHeading
        title="All Queues & Transaction History"
        subtitle="Monitor all registrar transactions, including completed, skipped, cancelled, and active tickets."
        actions={
          <div className="flex gap-2">
            <button
              className="btn-secondary"
              onClick={clearFilters}
            >
              Clear Filters
            </button>

            <RefreshButton onClick={() => refresh()} />
          </div>
        }
      />

      <ErrorBox message={error} retry={refresh} />

      <Card>
        <div className="grid gap-3 p-5 md:grid-cols-4">
          <div className="md:col-span-2">
            <SearchField
              value={search}
              onChange={changeSearch}
              placeholder="Search ticket, student name, or student number"
            />
          </div>

          <select
            className="field"
            value={status}
            onChange={(e) => changeStatus(e.target.value)}
          >
            <option value="">All Statuses</option>
            <option value="waiting">Waiting</option>
            <option value="called">Called</option>
            <option value="serving">Serving</option>
            <option value="completed">Completed</option>
            <option value="skipped">Skipped</option>
            <option value="cancelled">Cancelled</option>
          </select>

          <div className="flex gap-2">
            <input
              className="field"
              type="date"
              aria-label="Filter queue date"
              value={date}
              onChange={(e) => changeDate(e.target.value)}
            />

            {date && (
              <button
                type="button"
                className="btn-secondary"
                onClick={() => changeDate('')}
              >
                All Dates
              </button>
            )}
          </div>
        </div>

        <div className="flex flex-wrap items-center justify-between gap-3 border-y border-slate-100 bg-primary-50 px-5 py-3">
          <div className="flex items-center gap-2">
            <span className="text-sm font-semibold text-primary-800">
              {status
                ? `${status.charAt(0).toUpperCase()}${status.slice(1)} Transactions`
                : 'All Transactions'}
            </span>

            <span className="rounded-full bg-white px-3 py-1 text-xs font-bold text-primary-700">
              {total}
            </span>
          </div>

          <span className="text-xs text-slate-500">
            {date || 'All Dates'} · Auto-refresh every 5 seconds
          </span>
        </div>

        {loading ? (
          <Spinner />
        ) : tickets.length > 0 ? (
          table(
            [
              'Ticket',
              'Student',
              'Service',
              'Counter',
              'Staff',
              'Status',
              'Priority',
              'Date Created',
              'Completed At'
            ],
            tickets,
            (q) => (
              <tr key={q.id}>
                <td className="font-bold !text-primary-700">
                  {q.queue_number}
                </td>

                <td>
                  <div className="font-semibold text-slate-800">
                    {q.student_name}
                  </div>
                  <div className="text-xs text-slate-500">
                    {q.student_number}
                  </div>
                </td>

                <td>{q.service_name}</td>

                <td>
                  {q.counter_number
                    ? `Counter ${q.counter_number}`
                    : '—'}
                </td>

                <td>{q.staff_name || '—'}</td>

                <td>
                  <Badge value={q.status} />
                </td>

                <td>
                  {q.status === 'waiting' ? (
                    <select
                      className="field !w-auto !py-1.5"
                      aria-label={`Priority for ${q.queue_number}`}
                      value={Number(q.priority_level)}
                      onChange={(e) =>
                        priority(q, e.target.value)
                      }
                    >
                      <option value={0}>Normal</option>
                      <option value={1}>Priority</option>
                      <option value={2}>Urgent</option>
                    </select>
                  ) : (
                    Number(q.priority_level) === 2
                      ? 'Urgent'
                      : Number(q.priority_level) === 1
                        ? 'Priority'
                        : 'Normal'
                  )}
                </td>

                <td className="whitespace-nowrap">
                  {shortDate(q.created_at)}
                </td>

                <td className="whitespace-nowrap">
                  {q.completed_at
                    ? shortDate(q.completed_at)
                    : '—'}
                </td>
              </tr>
            )
          )
        ) : (
          <Empty
            title="No matching transactions"
            text="Try changing the status, date, or search filters."
          />
        )}

        <div className="flex flex-wrap items-center justify-between gap-3 border-t px-5 py-4">
          <div className="text-sm text-slate-500">
            Showing{' '}
            {total === 0 ? 0 : (page - 1) * limit + 1}
            {' - '}
            {Math.min(page * limit, total)}
            {' of '}
            {number(total)}
            {' transactions'}
          </div>

          <div className="flex items-center gap-2">
            <button
              className="btn-secondary"
              disabled={page <= 1}
              onClick={() =>
                setPage((p) => Math.max(1, p - 1))
              }
            >
              Previous
            </button>

            <span className="px-3 py-2 text-sm font-semibold">
              Page {page} of {totalPages}
            </span>

            <button
              className="btn-secondary"
              disabled={page >= totalPages}
              onClick={() => setPage((p) => p + 1)}
            >
              Next
            </button>
          </div>
        </div>
      </Card>
    </>
  );
}