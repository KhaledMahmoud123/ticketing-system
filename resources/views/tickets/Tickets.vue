<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import Dialog from '../../Components/ui/Dialog.vue';

interface TicketType {
    id: number;
    name: string;
    description?: string | null;
    role_id?: number | null;
    role?: { id: number; name: string } | null;
    priority?: 'low' | 'medium' | 'high';
}

interface TicketRow {
    id: number;
    title: string;
    body: string;
    type_id: number;
    user_id: number | null;
    owner_type: string | null;
    owner_id: string | number | null;
    owner_label?: string | null;
    priority: 'low' | 'medium' | 'high';
    status: 'open' | 'processing' | 'closed';
    created_at: string | null;
    unread_count?: number;
    type?: { id: number; name: string } | null;
    user?: { id: number; name: string } | null;
}

interface TicketReply {
    id: number;
    ticket_id: number;
    sender_type: string;
    sender_id: string;
    message: string;
    is_read?: boolean;
    created_at: string | null;
}

interface TicketFile {
    id: number;
    ticket_id: number;
    name: string;
    type: string;
    created_at: string | null;
    url?: string | null;
}

interface TicketDetails extends TicketRow {
    replies: TicketReply[];
    files: TicketFile[];
}

const page = usePage<{
    data: TicketRow[];
    links: { label: string; url: string | null; active: boolean }[];
    filters: {
        search: string | null;
        status: string | null;
        priority: string | null;
        type_id: string | null;
    };
    types: TicketType[];
    statuses: string[];
    priorities: string[];
    roles: { id: number; name: string }[];
}>();

const rows = computed(() => page.props.data || []);
const links = computed(() => page.props.links || []);
const types = computed(() => page.props.types || []);
const statuses = computed(() => page.props.statuses || []);
const priorities = computed(() => page.props.priorities || []);
const roles = computed(() => page.props.roles || []);

const filters = ref({
    search: page.props.filters?.search || '',
    status: page.props.filters?.status || '',
    priority: page.props.filters?.priority || '',
    type_id: page.props.filters?.type_id || '',
});

const editDialogOpen = ref(false);
const detailsDialogOpen = ref(false);
const chatDialogOpen = ref(false);
const typeDialogOpen = ref(false);
const saving = ref(false);
const typeSaving = ref(false);
const detailsLoading = ref(false);
const chatLoading = ref(false);
const errors = ref<Record<string, string>>({});
const typeErrors = ref<Record<string, string>>({});
const selectedTicket = ref<TicketRow | null>(null);
const activeTicket = ref<TicketDetails | null>(null);
const chatTicket = ref<TicketDetails | null>(null);
const chatMessagesRef = ref<HTMLElement | null>(null);
const chatFileInput = ref<HTMLInputElement | null>(null);
const chatFiles = ref<File[]>([]);
const unreadOverrides = ref<Record<number, number>>({});
const editingTypeId = ref<number | null>(null);

interface TicketTypeForm {
    name: string;
    description: string;
    role_id: string | number;
    priority: 'low' | 'medium' | 'high';
}

const form = ref({
    title: '',
    body: '',
    type_id: '',
    status: 'open',
});

const replyForm = ref({
    message: '',
});

const typeForm = ref<TicketTypeForm>({
    name: '',
    description: '',
    role_id: '',
    priority: 'low',
});

let timer: ReturnType<typeof setTimeout> | null = null;

watch(
    () => filters.value.search,
    () => {
        if (timer) {
            clearTimeout(timer);
        }

        timer = setTimeout(() => {
            applyFilters();
        }, 350);
    },
    { immediate: false }
);

const mapValidationErrors = (error: any) => {
    const responseErrors = error?.response?.data?.errors;

    if (!responseErrors || typeof responseErrors !== 'object') {
        return null;
    }

    return Object.fromEntries(
        Object.entries(responseErrors).map(([key, value]) => [
            key,
            Array.isArray(value) ? String(value[0] ?? '') : String(value ?? ''),
        ])
    );
};

const applyFilters = () => {
    router.get(
        route('tickets-management.index'),
        { ...filters.value, page: 1 },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: [
                'data',
                'links',
                'filters',
                'types',
                'statuses',
                'priorities',
            ],
        }
    );
};

const clearFilters = () => {
    filters.value = {
        search: '',
        status: '',
        priority: '',
        type_id: '',
    };
    applyFilters();
};

const handlePageClick = (url: string) => {
    try {
        const urlObj = new URL(url, window.location.origin);
        const linkParams = new URLSearchParams(urlObj.search);
        const pageParam = linkParams.get('page') ?? '1';

        router.get(
            route('tickets-management.index'),
            { ...filters.value, page: pageParam },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: [
                    'data',
                    'links',
                    'filters',
                    'types',
                    'statuses',
                    'priorities',
                ],
            }
        );
    } catch {
        window.location.href = url;
    }
};

const reloadPage = () => {
    router.reload({
        preserveState: true,
        preserveScroll: true,
        only: ['data', 'links', 'filters', 'types', 'statuses', 'priorities'],
    });
};

const formatDate = (value: string | null) => {
    if (!value) return '-';

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours24 = date.getHours();
    const hours = String(hours24 % 12 || 12).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const period = hours24 >= 12 ? 'pm' : 'am';

    return `${day}-${month}-${year} ${hours}:${minutes}${period}`;
};

const priorityClass = (value: string) => {
    if (value === 'high') {
        return 'inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800';
    }

    if (value === 'medium') {
        return 'inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800';
    }

    return 'inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800';
};

const statusClass = (value: string) => {
    if (value === 'closed') {
        return 'inline-flex items-center rounded-full bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-800';
    }

    if (value === 'processing') {
        return 'inline-flex items-center rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800';
    }

    return 'inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800';
};

const priorityLabel = (value: string) => {
    if (value === 'high') return 'Urgent';
    if (value === 'medium') return 'Important';
    if (value === 'low') return 'Normal';
    return value;
};

const statusLabel = (value: string) => {
    if (value === 'open') return 'New';
    if (value === 'processing') return 'In Progress';
    if (value === 'closed') return 'Resolved';
    return value;
};

const openTypeDialog = () => {
    typeDialogOpen.value = true;
    resetTypeForm();
};

const resetTypeForm = () => {
    editingTypeId.value = null;
    typeForm.value = {
        name: '',
        description: '',
        role_id: '',
        priority: 'low',
    };
    typeErrors.value = {};
};

const editType = (item: TicketType) => {
    editingTypeId.value = item.id;
    typeForm.value = {
        name: item.name || '',
        description: item.description || '',
        role_id: item.role_id ?? '',
        priority: (item as any).priority || 'low',
    };
    typeErrors.value = {};
};

const saveType = async () => {
    typeSaving.value = true;
    typeErrors.value = {};

    const payload = {
        name: typeForm.value.name,
        description: typeForm.value.description || null,
        role_id: typeForm.value.role_id || null,
        priority: typeForm.value.priority,
    };

    try {
        if (editingTypeId.value) {
            await axios.patch(
                route(
                    'tickets-management.ticket-types.update',
                    editingTypeId.value
                ),
                payload
            );
        } else {
            await axios.post(
                route('tickets-management.ticket-types.store'),
                payload
            );
        }

        resetTypeForm();
        reloadPage();
    } catch (error: any) {
        const validation = mapValidationErrors(error);
        if (validation) {
            typeErrors.value = validation;
            return;
        }

        alert(error?.response?.data?.message || 'Unable to save ticket type.');
    } finally {
        typeSaving.value = false;
    }
};

const deleteType = async (item: TicketType) => {
    if (!window.confirm(`Delete ticket type ${item.name}?`)) {
        return;
    }

    try {
        await axios.delete(
            route('tickets-management.ticket-types.destroy', item.id)
        );

        if (editingTypeId.value === item.id) {
            resetTypeForm();
        }

        reloadPage();
    } catch (error: any) {
        alert(
            error?.response?.data?.message || 'Unable to delete ticket type.'
        );
    }
};

const openEditDialog = (row: TicketRow) => {
    selectedTicket.value = row;
    errors.value = {};
    form.value = {
        title: row.title,
        body: row.body,
        type_id: String(row.type_id),
        status: row.status,
    };
    editDialogOpen.value = true;
};

const openDetailsDialog = async (row: TicketRow) => {
    detailsDialogOpen.value = true;
    detailsLoading.value = true;
    activeTicket.value = null;

    try {
        const response = await axios.get(
            route('tickets-management.tickets.show', row.id)
        );
        activeTicket.value = response.data;
    } catch (error: any) {
        alert(
            error?.response?.data?.message || 'Unable to load ticket details.'
        );
        detailsDialogOpen.value = false;
    } finally {
        detailsLoading.value = false;
    }
};

const openChatDialog = async (row: TicketRow) => {
    chatDialogOpen.value = true;
    chatLoading.value = true;
    chatTicket.value = null;
    replyForm.value.message = '';
    chatFiles.value = [];
    chatUserAtBottom.value = true;

    try {
        const response = await axios.get(
            route('tickets-management.tickets.chat', row.id)
        );
        chatTicket.value = response.data;

        // FIX: wait longer for DOM + dialog render
        await nextTick();
        setTimeout(() => {
            forceScrollToBottom();
        }, 50);
    } catch (error: any) {
        try {
            const fallback = await axios.get(
                route('tickets-management.tickets.show', row.id)
            );
            chatTicket.value = fallback.data;

            // FIX: wait longer for DOM + dialog render
            await nextTick();
            setTimeout(() => {
                forceScrollToBottom();
            }, 50);
        } catch {
            alert(
                error?.response?.data?.message || 'Unable to load ticket chat.'
            );
            chatDialogOpen.value = false;
        }
    } finally {
        chatLoading.value = false;
    }
};

const buildPayload = () => ({
    title: form.value.title,
    body: form.value.body,
    type_id: form.value.type_id ? Number(form.value.type_id) : null,
    status: form.value.status,
});

const updateTicket = async () => {
    if (!selectedTicket.value) return;

    saving.value = true;
    errors.value = {};

    try {
        await axios.patch(
            route('tickets-management.tickets.update', selectedTicket.value.id),
            buildPayload()
        );
        editDialogOpen.value = false;
        selectedTicket.value = null;
        reloadPage();
    } catch (error: any) {
        const validation = mapValidationErrors(error);
        if (validation) {
            errors.value = validation;
            return;
        }

        alert(error?.response?.data?.message || 'Unable to update ticket.');
    } finally {
        saving.value = false;
    }
};

const deleteTicket = async (row: TicketRow) => {
    if (!window.confirm(`Delete ticket #${row.id}?`)) {
        return;
    }

    try {
        await axios.delete(route('tickets-management.tickets.destroy', row.id));
        reloadPage();
    } catch (error: any) {
        alert(error?.response?.data?.message || 'Unable to delete ticket.');
    }
};

const refreshActiveTicket = async () => {
    if (!activeTicket.value) return;

    const response = await axios.get(
        route('tickets-management.tickets.show', activeTicket.value.id)
    );
    activeTicket.value = response.data;
};

const refreshChatTicket = async () => {
    if (!chatTicket.value) return;

    const response = await axios.get(
        route('tickets-management.tickets.show', chatTicket.value.id)
    );
    chatTicket.value = response.data;
    await smartScrollToBottom();
};

const chatUserAtBottom = ref(true);
const SCROLL_THRESHOLD = 150; // pixels from bottom to consider "at bottom"

// Check if user is scrolled to bottom
const isUserAtBottom = () => {
    const element = chatMessagesRef.value;
    if (!element) return true;

    const distanceFromBottom =
        element.scrollHeight - element.scrollTop - element.clientHeight;
    return distanceFromBottom < SCROLL_THRESHOLD;
};

// Handle user scroll - track if they're at bottom or scrolled up
const handleChatScroll = () => {
    chatUserAtBottom.value = isUserAtBottom();
};

// Force scroll to bottom (used on initial open)
const forceScrollToBottom = () => {
    const element = chatMessagesRef.value;
    if (!element) {
        console.log('element is null:', element);
        return;
    }

    console.log(
        'Scrolling to bottom. scrollHeight:',
        element.scrollHeight,
        'clientHeight:',
        element.clientHeight
    );
    element.scrollTop = element.scrollHeight;
    chatUserAtBottom.value = true;
};

// Smart scroll to bottom (only if user is at bottom or it's first load)
const smartScrollToBottom = async () => {
    await nextTick();

    if (!chatUserAtBottom.value && chatTicket.value?.replies.length) {
        // User has scrolled up, don't force scroll
        return;
    }

    const element = chatMessagesRef.value;
    if (!element) return;

    element.scrollTop = element.scrollHeight;
    chatUserAtBottom.value = true;
};

// Watch for new messages and auto-scroll if user is at bottom
watch(
    () => chatTicket.value?.replies.length,
    async () => {
        if (chatUserAtBottom.value) {
            await smartScrollToBottom();
        }
    }
);

const fileName = (file: TicketFile) => {
    const parts = String(file.name || '').split('/');
    return parts[parts.length - 1] || file.name;
};

const fileUrl = (file: TicketFile) => {
    const value =
        String(file.url || '').trim() || String(file.name || '').trim();
    if (!value) return '#';
    if (/^https?:\/\//i.test(value)) return value;
    // Backend returns asset() URL, use as-is
    return value.startsWith('/') ? value : `/${value}`;
};

const openTicketFile = (file: TicketFile) => {
    const url = fileUrl(file);
    window.open(url, '_blank', 'noopener,noreferrer');
};

const onChatFilesChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    chatFiles.value = Array.from(input.files || []);
};

const removeChatFile = (index: number) => {
    chatFiles.value = chatFiles.value.filter(
        (_, currentIndex) => currentIndex !== index
    );
    if (chatFileInput.value) {
        chatFileInput.value.value = '';
    }
};

const unreadForRow = (row: TicketRow) =>
    unreadOverrides.value[row.id] ?? row.unread_count ?? 0;

const isUserReply = (reply: TicketReply) => reply.sender_type.includes('User');

const authUser = computed<any | null>(() => {
    return (page.props as any)?.auth?.user ?? null;
});

const isSuperUser = ref(false);

watch(authUser, async (user) => {
  if (!user) {
    isSuperUser.value = false;
    return;
  }

  const { data } = await axios.get(route('get.auth'));
  isSuperUser.value = data.role === 'super_admin';
}, { immediate: true });

const senderLabel = (reply: TicketReply) => {
    if (reply.sender_type.includes('Student')) {
        return isSuperUser.value ? `Student (${reply.sender_id})` : 'Student';
    }

    return isSuperUser.value ? `User (${reply.sender_id})` : 'User';
};

const canManageTypes = computed(() => {
    // Super users can manage types
    if (isSuperUser.value) {
        return true;
    }

    // Check if user has manage ticket types permission
    const user = authUser.value;
    if (!user) {
        return false;
    }

    const permissions = Array.isArray(user.permissions) ? user.permissions : [];

    return (
        permissions.includes('tickets-management.ticket-types.manage') ||
        permissions.includes('tickets-management') ||
        permissions.some((p: string) => p.includes('ticket-types'))
    );
});

const addReply = async () => {
    if (!chatTicket.value) return;

    const hasMessage = replyForm.value.message.trim().length > 0;
    const hasFiles = chatFiles.value.length > 0;
    if (!hasMessage && !hasFiles) return;

    try {
        const payload = new FormData();
        payload.append('ticket_id', String(chatTicket.value.id));
        payload.append('message', replyForm.value.message);
        chatFiles.value.forEach((file) => {
            payload.append('files[]', file);
        });

        await axios.post(
            route('tickets-management.ticket-replies.store'),
            payload,
            {
                headers: { 'Content-Type': 'multipart/form-data' },
            }
        );

        replyForm.value = {
            message: '',
        };
        chatFiles.value = [];
        if (chatFileInput.value) {
            chatFileInput.value.value = '';
        }

        unreadOverrides.value = {
            ...unreadOverrides.value,
            [chatTicket.value.id]: 0,
        };

        await refreshChatTicket();
    } catch (error: any) {
        alert(error?.response?.data?.message || 'Unable to add reply.');
    }
};
</script>

<template>
    <AppLayout title="Tickets">
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Tickets Management
                </h2>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
                        @click="reloadPage"
                    >
                        Refresh
                    </button>
                    <button
                        v-if="canManageTypes"
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg bg-indigo-700 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-800"
                        @click="openTypeDialog"
                    >
                        Manage Types
                    </button>
                </div>
            </div>
        </template>

        <div
            class="mb-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
        >
            <div class="grid grid-cols-1 gap-3 md:grid-cols-5">
                <input
                    v-model="filters.search"
                    type="text"
                    placeholder="Search by title, body, owner..."
                    class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-200"
                />

                <select
                    v-model="filters.status"
                    class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-200"
                >
                    <option value="">All Statuses</option>
                    <option v-for="item in statuses" :key="item" :value="item">
                        {{ statusLabel(item) }}
                    </option>
                </select>

                <select
                    v-model="filters.priority"
                    class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-200"
                >
                    <option value="">All Priorities</option>
                    <option
                        v-for="item in priorities"
                        :key="item"
                        :value="item"
                    >
                        {{ priorityLabel(item) }}
                    </option>
                </select>

                <select
                    v-model="filters.type_id"
                    class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-200"
                >
                    <option value="">All Types</option>
                    <option
                        v-for="item in types"
                        :key="item.id"
                        :value="String(item.id)"
                    >
                        {{ item.name }}
                    </option>
                </select>

                <div class="flex items-center">
                    <div class="flex gap-2">
                        <button
                            class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                            @click="clearFilters"
                        >
                            Clear
                        </button>
                        <button
                            class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800"
                            @click="applyFilters"
                        >
                            Apply
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="overflow-hidden rounded-lg bg-white shadow-lg">
            <div class="overflow-x-auto">
                <table
                    class="min-w-full table-auto text-xs [&_th]:px-2 [&_th]:py-2 [&_th]:text-left [&_td]:px-2 [&_td]:py-1.5 [&_td]:text-left"
                >
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Owner</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th class="min-w-[160px] whitespace-nowrap">
                                Date
                            </th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in rows"
                            :key="row.id"
                            class="border-b hover:bg-gray-50"
                        >
                            <td class="max-w-[280px]">
                                <div class="font-medium text-gray-900 truncate">
                                    {{ row.title }}
                                </div>
                                <div class="text-xs text-gray-500 truncate">
                                    {{ row.body }}
                                </div>
                            </td>
                            <td>{{ row.type?.name || '-' }}</td>
                            <td>
                                <div class="text-xs text-gray-500">
                                    {{
                                        row.owner_label || row.owner_type || '-'
                                    }}
                                </div>
                            </td>
                            <td>
                                <span :class="priorityClass(row.priority)">{{
                                    priorityLabel(row.priority)
                                }}</span>
                            </td>
                            <td>
                                <span :class="statusClass(row.status)">{{
                                    statusLabel(row.status)
                                }}</span>
                            </td>
                            <td class="min-w-[160px] whitespace-nowrap">
                                {{ formatDate(row.created_at) }}
                            </td>
                            <td class="text-end">
                                <div
                                    class="inline-flex items-center justify-end gap-1"
                                >
                                    <button
                                        type="button"
                                        class="relative inline-flex items-center rounded-md p-1 text-gray-700 hover:bg-gray-100 focus:ring-2 focus:ring-violet-300 outline-none"
                                        @click="openChatDialog(row)"
                                    >
                                        <svg
                                            class="w-5 h-5 text-violet-600"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.8"
                                            viewBox="0 0 24 24"
                                            xmlns="http://www.w3.org/2000/svg"
                                            aria-hidden="true"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M2.25 12.76c0 1.6.84 3.04 2.2 4.09a8.97 8.97 0 0 0 5.55 1.9c.72 0 1.42-.08 2.09-.23a.75.75 0 0 1 .57.09l2.1 1.27a.75.75 0 0 0 1.13-.67l-.18-1.96a.75.75 0 0 1 .24-.64 6.56 6.56 0 0 0 1.85-4.52c0-3.73-3.58-6.75-8-6.75s-8 3.02-8 6.75Z"
                                            />
                                        </svg>
                                        <span
                                            v-if="unreadForRow(row) > 0"
                                            class="absolute -right-1 -top-1 inline-flex min-w-[18px] items-center justify-center rounded-full bg-rose-600 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white"
                                        >
                                            {{
                                                unreadForRow(row) > 99
                                                    ? '99+'
                                                    : unreadForRow(row)
                                            }}
                                        </span>
                                        <span class="sr-only">Chat</span>
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex items-center rounded-md p-1 text-gray-700 hover:bg-gray-100 focus:ring-2 focus:ring-blue-300 outline-none"
                                        @click="openDetailsDialog(row)"
                                    >
                                        <svg
                                            class="w-5 h-5 text-blue-500"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.8"
                                            viewBox="0 0 24 24"
                                            xmlns="http://www.w3.org/2000/svg"
                                            aria-hidden="true"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"
                                            />
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                            />
                                        </svg>
                                        <span class="sr-only">View</span>
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex items-center rounded-md p-1 text-gray-700 hover:bg-gray-100 focus:ring-2 focus:ring-green-300 outline-none"
                                        @click="openEditDialog(row)"
                                    >
                                        <svg
                                            class="w-5 h-5 text-green-400"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2"
                                            viewBox="0 0 24 24"
                                            xmlns="http://www.w3.org/2000/svg"
                                            aria-hidden="true"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"
                                            />
                                        </svg>
                                        <span class="sr-only">Edit</span>
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex items-center rounded-md p-1 text-gray-700 hover:bg-gray-100 focus:ring-2 focus:ring-red-300 outline-none"
                                        @click="deleteTicket(row)"
                                    >
                                        <svg
                                            class="w-5 h-5 text-red-600"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.5"
                                            viewBox="0 0 24 24"
                                            xmlns="http://www.w3.org/2000/svg"
                                            aria-hidden="true"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
                                            ></path>
                                        </svg>
                                        <span class="sr-only">Delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td
                                colspan="8"
                                class="px-4 py-10 text-center text-gray-500"
                            >
                                No tickets found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="links.length" class="flex justify-end p-4">
                <div class="flex flex-wrap divide-x-2 rounded bg-white shadow">
                    <template v-for="(link, index) in links" :key="index">
                        <button
                            v-if="link.url"
                            @click="handlePageClick(link.url)"
                            class="min-w-[2rem] min-h-[2rem] my-1 mx-1 px-1.5 rounded transition-colors inline-block"
                            :class="{
                                'bg-blue-600 text-white cursor-default':
                                    link.active,
                                'hover:bg-gray-100 text-gray-700': !link.active,
                            }"
                            :disabled="link.active"
                        >
                            <span v-html="link.label"></span>
                        </button>
                        <button
                            v-else
                            class="min-w-[2rem] min-h-[2rem] my-1 mx-1 px-1.5 rounded transition-colors inline-block opacity-50 cursor-not-allowed text-gray-700"
                            disabled
                        >
                            <span v-html="link.label"></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>

        <Dialog
            title="Manage Ticket Types"
            :open="typeDialogOpen"
            @close="typeDialogOpen = false"
            maxWidthClass="md:w-[860px]"
        >
            <div class="space-y-4">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div>
                        <label
                            class="mb-2 block text-sm font-medium text-gray-700"
                            >Name</label
                        >
                        <input
                            v-model="typeForm.name"
                            type="text"
                            class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                        />
                        <div
                            v-if="typeErrors.name"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ typeErrors.name }}
                        </div>
                    </div>
                    <div>
                        <label
                            class="mb-2 block text-sm font-medium text-gray-700"
                            >Role</label
                        >
                        <select
                            v-model="typeForm.role_id"
                            class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                        >
                            <option value="">No role</option>
                            <option
                                v-for="role in roles"
                                :key="role.id"
                                :value="role.id"
                            >
                                {{ role.name }}
                            </option>
                        </select>
                        <div
                            v-if="typeErrors.role_id"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ typeErrors.role_id }}
                        </div>
                    </div>
                    <div>
                        <label
                            class="mb-2 block text-sm font-medium text-gray-700"
                            >Priority</label
                        >
                        <select
                            v-model="typeForm.priority"
                            class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                        >
                            <option value="low">Normal</option>
                            <option value="medium">Important</option>
                            <option value="high">Urgent</option>
                        </select>
                        <div
                            v-if="typeErrors.priority"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ typeErrors.priority }}
                        </div>
                    </div>
                    <div class="flex items-end gap-2">
                        <button
                            type="button"
                            class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                            @click="resetTypeForm"
                        >
                            Clear
                        </button>
                        <button
                            type="button"
                            class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800"
                            :disabled="typeSaving"
                            @click="saveType"
                        >
                            {{ editingTypeId ? 'Update' : 'Add' }}
                        </button>
                    </div>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700"
                        >Description</label
                    >
                    <textarea
                        v-model="typeForm.description"
                        rows="2"
                        class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                    ></textarea>
                    <div
                        v-if="typeErrors.description"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ typeErrors.description }}
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg border border-gray-200">
                    <table
                        class="min-w-full table-auto text-xs [&_th]:px-2 [&_th]:py-2 [&_th]:text-left [&_td]:px-2 [&_td]:py-1.5 [&_td]:text-left"
                    >
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Priority</th>
                                <th>Description</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="item in types"
                                :key="item.id"
                                class="border-b hover:bg-gray-50"
                            >
                                <td>{{ item.name }}</td>
                                <td>{{ item.role?.name || '-' }}</td>
                                <td>
                                    <span
                                        :class="priorityClass((item as any).priority)"
                                        >{{
                                            priorityLabel(
                                                (item as any).priority
                                            )
                                        }}</span
                                    >
                                </td>
                                <td class="max-w-[300px] truncate">
                                    {{ item.description || '-' }}
                                </td>
                                <td class="text-end">
                                    <div
                                        class="inline-flex items-center justify-end gap-2"
                                    >
                                        <button
                                            type="button"
                                            class="text-xs font-medium text-blue-700 hover:text-blue-800"
                                            @click="editType(item)"
                                        >
                                            <svg
                                                class="w-5 h-5 text-green-400"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2"
                                                viewBox="0 0 24 24"
                                                xmlns="http://www.w3.org/2000/svg"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"
                                                ></path>
                                            </svg>
                                        </button>
                                        <button
                                            type="button"
                                            class="text-xs font-medium text-red-600 hover:text-red-700"
                                            @click="deleteType(item)"
                                        >
                                            <svg
                                                class="w-5 h-5 text-red-600"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="1.5"
                                                viewBox="0 0 24 24"
                                                xmlns="http://www.w3.org/2000/svg"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
                                                ></path>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!types.length">
                                <td
                                    colspan="4"
                                    class="px-4 py-6 text-center text-gray-500"
                                >
                                    No ticket types found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </Dialog>

        <Dialog
            title="Edit Ticket"
            :open="editDialogOpen"
            @close="editDialogOpen = false"
            maxWidthClass="md:w-[820px]"
        >
            <div class="space-y-4">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <div>
                        <label
                            class="mb-2 block text-sm font-medium text-gray-700"
                            >Title</label
                        >
                        <input
                            v-model="form.title"
                            type="text"
                            class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                        />
                        <div
                            v-if="errors.title"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ errors.title }}
                        </div>
                    </div>
                    <div>
                        <label
                            class="mb-2 block text-sm font-medium text-gray-700"
                            >Type</label
                        >
                        <select
                            v-model="form.type_id"
                            class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                        >
                            <option value="">Select type</option>
                            <option
                                v-for="item in types"
                                :key="item.id"
                                :value="String(item.id)"
                            >
                                {{ item.name }}
                            </option>
                        </select>
                        <div
                            v-if="errors.type_id"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ errors.type_id }}
                        </div>
                    </div>
                </div>

                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700"
                        >Body</label
                    >
                    <textarea
                        v-model="form.body"
                        rows="4"
                        class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                    ></textarea>
                    <div v-if="errors.body" class="mt-1 text-sm text-red-600">
                        {{ errors.body }}
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3">
                    <div>
                        <label
                            class="mb-2 block text-sm font-medium text-gray-700"
                            >Status</label
                        >
                        <select
                            v-model="form.status"
                            class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                        >
                            <option value="open">New</option>
                            <option value="processing">In Progress</option>
                            <option value="closed">Resolved</option>
                        </select>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button
                        type="button"
                        class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                        @click="editDialogOpen = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800"
                        :disabled="saving"
                        @click="updateTicket"
                    >
                        Save Changes
                    </button>
                </div>
            </div>
        </Dialog>

        <Dialog
            title="Ticket Details"
            :open="detailsDialogOpen"
            @close="detailsDialogOpen = false"
            maxWidthClass="md:w-[1000px]"
        >
            <div v-if="detailsLoading" class="py-8 text-center text-gray-500">
                Loading...
            </div>

            <div v-else-if="activeTicket" class="space-y-4">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <div>
                        <div class="text-xs text-gray-500">Title</div>
                        <div class="font-medium text-gray-900">
                            {{ activeTicket.title }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Type</div>
                        <div class="font-medium text-gray-900">
                            {{ activeTicket.type?.name || '-' }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Priority</div>
                        <span :class="priorityClass(activeTicket.priority)">{{
                            priorityLabel(activeTicket.priority)
                        }}</span>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Status</div>
                        <span :class="statusClass(activeTicket.status)">{{
                            statusLabel(activeTicket.status)
                        }}</span>
                    </div>
                </div>

                <div>
                    <div class="text-xs text-gray-500">Body</div>
                    <div
                        class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-800 whitespace-pre-wrap"
                    >
                        {{ activeTicket.body }}
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 p-3">
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="font-medium text-gray-900">Files</h3>
                    </div>

                    <div
                        v-if="activeTicket.files.length"
                        class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <button
                            v-for="file in activeTicket.files"
                            :key="file.id"
                            type="button"
                            class="flex items-center gap-3 rounded-xl border border-gray-200 bg-white p-3 text-left shadow-sm transition hover:border-blue-300 hover:bg-blue-50"
                            @click="openTicketFile(file)"
                        >
                            <div
                                class="flex h-11 w-11 items-center justify-center rounded-full bg-blue-100 text-blue-700"
                            >
                                <svg
                                    class="h-5 w-5"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    viewBox="0 0 24 24"
                                    xmlns="http://www.w3.org/2000/svg"
                                    aria-hidden="true"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M19.5 7.5l-7.5-6-7.5 6v9a3 3 0 003 3h9a3 3 0 003-3v-9z"
                                    />
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M12 3v6h6"
                                    />
                                </svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="truncate text-sm font-medium text-gray-900"
                                >
                                    {{ fileName(file) }}
                                </div>
                                <div class="truncate text-xs text-gray-500">
                                    {{ file.type }}
                                </div>
                            </div>
                        </button>
                    </div>
                    <div v-else class="text-sm text-gray-500">
                        No files yet.
                    </div>
                </div>
            </div>
        </Dialog>

        <Dialog
            title="Ticket Chat"
            :open="chatDialogOpen"
            @close="chatDialogOpen = false"
            maxWidthClass="md:w-[980px]"
        >
            <div v-if="chatLoading" class="py-10 text-center text-gray-500">
                Loading chat...
            </div>

            <div v-else-if="chatTicket" class="space-y-4">
                <div
                    class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3"
                >
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <span class="text-slate-600">{{
                            chatTicket.title
                        }}</span>
                        <span :class="priorityClass(chatTicket.priority)">{{
                            priorityLabel(chatTicket.priority)
                        }}</span>
                        <span :class="statusClass(chatTicket.status)">{{
                            statusLabel(chatTicket.status)
                        }}</span>
                    </div>
                </div>

                <div
                    class="h-[350px] overflow-y-auto rounded-2xl border border-slate-200 bg-gradient-to-b from-slate-50 via-white to-slate-100 p-2"
                    ref="chatMessagesRef"
                    @scroll="handleChatScroll"
                >
                    <div class="space-y-2">
                        <div
                            v-for="reply in chatTicket.replies"
                            :key="reply.id"
                            class="flex"
                            :class="
                                isUserReply(reply)
                                    ? 'justify-end'
                                    : 'justify-start'
                            "
                        >
                            <div
                                class="max-w-[78%] rounded-2xl px-3 py-2 shadow-sm"
                                :class="
                                    isUserReply(reply)
                                        ? 'bg-blue-700 text-white'
                                        : 'border border-slate-200 bg-white text-slate-900'
                                "
                            >
                                <div
                                    class="mb-1 text-[11px] font-medium"
                                    :class="
                                        isUserReply(reply)
                                            ? 'text-blue-100'
                                            : 'text-slate-500'
                                    "
                                >
                                    {{ senderLabel(reply) }}
                                </div>
                                <div class="text-xs whitespace-pre-wrap">
                                    {{ reply.message }}
                                </div>
                                <div
                                    class="mt-2 flex items-center justify-between text-[11px]"
                                    :class="
                                        isUserReply(reply)
                                            ? 'text-blue-100'
                                            : 'text-slate-500'
                                    "
                                >
                                    <span>{{
                                        formatDate(reply.created_at)
                                    }}</span>
                                </div>
                            </div>
                        </div>
                        <div
                            v-if="!chatTicket.replies.length"
                            class="py-10 text-center text-sm text-slate-500"
                        >
                            No messages yet. Start the conversation.
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-3">
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <input
                            ref="chatFileInput"
                            type="file"
                            multiple
                            class="block w-full max-w-full rounded-lg border border-slate-300 px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-blue-700 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-white hover:file:bg-blue-800"
                            @change="onChatFilesChange"
                        />
                    </div>
                    <div
                        v-if="chatFiles.length"
                        class="mb-3 flex flex-wrap gap-2"
                    >
                        <div
                            v-for="(file, index) in chatFiles"
                            :key="`${file.name}-${index}`"
                            class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-700"
                        >
                            <span class="max-w-[220px] truncate">{{
                                file.name
                            }}</span>
                            <button
                                type="button"
                                class="font-semibold text-slate-500 hover:text-slate-800"
                                @click="removeChatFile(index)"
                            >
                                ×
                            </button>
                        </div>
                    </div>
                    <textarea
                        v-model="replyForm.message"
                        rows="3"
                        placeholder="Write your reply here..."
                        class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-200"
                    ></textarea>
                    <div class="mt-3 flex justify-end">
                        <button
                            type="button"
                            class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="
                                !replyForm.message.trim() && !chatFiles.length
                            "
                            @click="addReply"
                        >
                            Send Reply / Files
                        </button>
                    </div>
                </div>
            </div>
        </Dialog>
    </AppLayout>
</template>
