export type DeadlineTone = 'overdue' | 'soon' | 'normal' | 'none';

export function deadlineInfo(daysUntilDue: number, status: string, dueDate: string): { label: string; tone: DeadlineTone } {
    if (status === 'completed') {
        return { label: `Deadline ${dueDate}`, tone: 'none' };
    }

    if (daysUntilDue < 0) {
        const days = Math.abs(daysUntilDue);

        return { label: `Overdue by ${days} ${days === 1 ? 'day' : 'days'}`, tone: 'overdue' };
    }

    if (daysUntilDue === 0) {
        return { label: 'Due today', tone: 'soon' };
    }

    if (daysUntilDue === 1) {
        return { label: 'Due tomorrow', tone: 'soon' };
    }

    if (daysUntilDue <= 3) {
        return { label: `Due in ${daysUntilDue} days`, tone: 'soon' };
    }

    return { label: `Due ${dueDate}`, tone: 'normal' };
}

export const toneClasses: Record<DeadlineTone, string> = {
    overdue: 'rounded bg-red-100 px-1.5 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950 dark:text-red-300',
    soon: 'rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    normal: 'text-xs text-neutral-600 dark:text-neutral-400',
    none: 'text-xs text-neutral-500',
};