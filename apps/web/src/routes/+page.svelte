<script lang="ts">
  import { onMount } from 'svelte';

  let loading = true;
  let error: string | null = null;
  let summary: { contacts: number; campaigns: number; messages: number; revenue: number } | null = null;

  async function fetchSummary() {
    try {
      const res = await fetch('http://localhost:8080/metrics/summary');
      if (!res.ok) throw new Error('Failed to load metrics');
      summary = await res.json();
    } catch (e: any) {
      error = e?.message ?? 'Unknown error';
    } finally {
      loading = false;
    }
  }

  onMount(fetchSummary);
</script>

<div class="min-h-screen bg-base-200 p-6">
  <div class="max-w-6xl mx-auto">
    <h1 class="text-3xl font-bold mb-6">דאשבורד</h1>

    {#if loading}
      <div class="alert">טוען נתונים...</div>
    {:else if error}
      <div class="alert alert-error">שגיאה: {error}</div>
    {:else if summary}
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="stat bg-base-100 shadow">
          <div class="stat-title">לקוחות</div>
          <div class="stat-value">{summary.contacts}</div>
        </div>
        <div class="stat bg-base-100 shadow">
          <div class="stat-title">קמפיינים</div>
          <div class="stat-value">{summary.campaigns}</div>
        </div>
        <div class="stat bg-base-100 shadow">
          <div class="stat-title">מסרים</div>
          <div class="stat-value">{summary.messages}</div>
        </div>
        <div class="stat bg-base-100 shadow">
          <div class="stat-title">הכנסות</div>
          <div class="stat-value">₪{summary.revenue?.toFixed?.(2) ?? summary.revenue}</div>
        </div>
      </div>

      <div class="card bg-base-100 shadow">
        <div class="card-body">
          <h2 class="card-title">מה הלאה?</h2>
          <ul class="list-disc rtl list-inside">
            <li>ניהול מועדון לקוחות: יצירה וייבוא אנשי קשר</li>
            <li>אוטומציות שיווק: טריגרים לפי התנהגות</li>
            <li>דיווחי פתיחות/הקלקות/המרות בזמן אמת</li>
            <li>אינטגרציות: Shopify, WooCommerce, Wix, Magento, Zapier</li>
          </ul>
        </div>
      </div>
    {/if}
  </div>
</div>