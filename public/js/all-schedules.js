// Calendar and filter links render a new page. Also reset restored lists on browser Back/Forward.
const resetScheduleScroll = () => {
    document.querySelectorAll('.all-schedules-layout > .browse-schedules > [data-schedule-list]').forEach((list) => {
        list.scrollTop = 0;
    });
};

resetScheduleScroll();
window.addEventListener('pageshow', resetScheduleScroll);
