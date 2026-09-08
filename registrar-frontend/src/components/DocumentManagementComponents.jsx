import React, { useRef, useState, useEffect } from "react";
import PropTypes from "prop-types";
import {
  FolderIcon,
  DocumentTextIcon,
  ArchiveBoxIcon,
  TrashIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
} from "@heroicons/react/24/outline";

export const ManagementCard = ({
  id,
  name,
  isSelected,
  onClick,
  onArchive,
  onDelete,
  style,
  archiveTooltip = "Archive",
  deleteTooltip = "Delete",
  isDark = false,
}) => {
  return (
    <div
      onClick={onClick}
      className={`group relative shrink-0 w-[calc((100%-16px)/2)] h-28 md:w-[calc((100%-32px)/3)] p-2.5 rounded-xl border flex flex-col items-center justify-center text-center cursor-pointer transition-all duration-300 snap-start ${
        isSelected
          ? `${style.activeRing} ${style.bg} shadow-sm scale-102`
          : "border-gray-200 dark:border-[#3e4042] bg-gray-50/40 dark:bg-[#1a1b1c] hover:border-gray-300 dark:hover:border-gray-600 hover:-translate-y-1 hover:shadow-sm"
      }`}
    >
      {/* Archive button */}
      <button
        type="button"
        onClick={(e) => {
          e.stopPropagation();
          onArchive(id);
        }}
        className="absolute top-1.5 right-8 p-1 rounded-full bg-white/80 dark:bg-black/40 hover:bg-amber-50 dark:hover:bg-amber-950/40 text-gray-400 hover:text-amber-600 dark:hover:text-amber-400 z-10"
        title={archiveTooltip}
      >
        <ArchiveBoxIcon className="w-3.5 h-3.5" />
      </button>

      {/* Delete button */}
      <button
        type="button"
        onClick={(e) => {
          e.stopPropagation();
          onDelete(id);
        }}
        className="absolute top-1.5 right-1.5 p-1 rounded-full bg-white/80 dark:bg-black/40 hover:bg-red-50 dark:hover:bg-red-950/40 text-gray-400 hover:text-red-600 dark:hover:text-red-400 z-10"
        title={deleteTooltip}
      >
        <TrashIcon className="w-3.5 h-3.5" />
      </button>

      <div className="flex flex-col items-center justify-between flex-1 w-full pt-3">
        <div className="relative w-10 h-10 flex items-center justify-center">
          <FolderIcon className={`w-10 h-10 stroke-[1.5] transition-colors ${style.folder}`} />
          <div className="absolute inset-0 flex items-center justify-center pt-1.5">
            <DocumentTextIcon className={`w-4 h-4 stroke-[1.5] transition-colors ${style.inner}`} />
          </div>
        </div>
        <div className="h-8 w-full flex items-center justify-center">
          <span
            className={`text-[10px] font-bold tracking-tight leading-tight line-clamp-3 text-center w-full px-1 wrap-break-word ${
              isSelected ? style.text : isDark ? "text-[#e4e6eb]" : "text-gray-700"
            }`}
          >
            {name}
          </span>
        </div>
      </div>
    </div>
  );
};

ManagementCard.propTypes = {
  id: PropTypes.oneOfType([PropTypes.string, PropTypes.number]).isRequired,
  name: PropTypes.string.isRequired,
  isSelected: PropTypes.bool.isRequired,
  onClick: PropTypes.func.isRequired,
  onArchive: PropTypes.func.isRequired,
  onDelete: PropTypes.func.isRequired,
  style: PropTypes.shape({
    folder: PropTypes.string.isRequired,
    inner: PropTypes.string.isRequired,
    bg: PropTypes.string.isRequired,
    text: PropTypes.string.isRequired,
    activeRing: PropTypes.string.isRequired,
  }).isRequired,
  archiveTooltip: PropTypes.string,
  deleteTooltip: PropTypes.string,
  isDark: PropTypes.bool,
};


export const ManagementCarousel = ({
  title,
  items,
  loading = false,
  emptyMessage = "No items found",
  renderItem,
  isDark = false,
}) => {
  const scrollRef = useRef(null);
  const [activeIndex, setActiveIndex] = useState(0);
  const totalPages = Math.ceil(items.length / 3);

  const itemIdsKey = items.map(item => item.document_type_id ?? item.certificate_type_id ?? item.id ?? item.name).join(',');
  const prevItemIdsKeyRef = useRef(itemIdsKey);

  const scrollToPage = (pageIdx) => {
    if (scrollRef.current) {
      const container = scrollRef.current;
      const cards = container.children;
      const targetCardIdx = pageIdx * 3;
      if (cards && cards[targetCardIdx]) {
        cards[targetCardIdx].scrollIntoView({
          behavior: "smooth",
          block: "nearest",
          inline: "start",
        });
        setActiveIndex(pageIdx);
      }
    }
  };

  // Only adjust page position when the list of items actually changes (e.g. search/delete),
  // not on parent re-renders when a card is selected or edited.
  useEffect(() => {
    if (prevItemIdsKeyRef.current !== itemIdsKey) {
      prevItemIdsKeyRef.current = itemIdsKey;
      if (activeIndex >= totalPages) {
        const newPage = Math.max(0, totalPages - 1);
        scrollToPage(newPage);
      }
    }
  }, [itemIdsKey, totalPages, activeIndex]);

  const scrollLeft = () => {
    const newPage = Math.max(0, activeIndex - 1);
    scrollToPage(newPage);
  };

  const scrollRight = () => {
    const newPage = Math.min(totalPages - 1, activeIndex + 1);
    scrollToPage(newPage);
  };

  const handleScroll = (e) => {
    const container = e.target;
    const { scrollLeft: leftOffset, clientWidth, scrollWidth } = container;
    const maxScroll = scrollWidth - clientWidth;
    if (maxScroll <= 0 || totalPages <= 1) return;

    const percentage = leftOffset / maxScroll;
    const newPage = Math.min(
      totalPages - 1,
      Math.max(0, Math.round(percentage * (totalPages - 1)))
    );
    setActiveIndex(newPage);
  };

  return (
    <div className="w-full bg-white dark:bg-[#242526] rounded-2xl p-4 border border-gray-200/80 dark:border-[#3e4042] shadow-sm flex flex-col justify-between gap-3">
      <div className="flex flex-col gap-3">
        <h3 className={`font-bold text-center text-sm tracking-wider w-full ${isDark ? "text-[#e4e6eb]" : "text-[#8B0000]"}`}>
          {title}
        </h3>

        <div className="relative w-full group/container">
          {/* Left Scroll Arrow */}
          {totalPages > 1 && (
            <button
              type="button"
              onClick={scrollLeft}
              className="absolute left-2 top-1/2 -translate-y-1/2 z-20 w-8 h-8 rounded-full bg-white dark:bg-[#242526] border border-gray-200 dark:border-[#3e4042] flex items-center justify-center shadow-md text-gray-500 hover:text-[#8B0000] dark:hover:text-[#F8BF1E] transition-all opacity-0 group-hover/container:opacity-100"
              title="Scroll Left"
            >
              <ChevronLeftIcon className="w-4 h-4 stroke-2" />
            </button>
          )}

          <div
            ref={scrollRef}
            onScroll={handleScroll}
            className="flex flex-row overflow-x-auto items-center gap-4 px-4 scroll-px-4 py-3 pb-4 select-none w-full snap-x snap-mandatory scroll-smooth no-scrollbar"
          >
            {loading ? (
              <div className="py-6 text-center text-xs text-gray-500 w-full animate-pulse">
                Loading...
              </div>
            ) : items.length === 0 ? (
              <div className="py-6 text-center text-xs text-gray-500 w-full">
                {emptyMessage}
              </div>
            ) : (
              items.map((item, idx) => renderItem(item, idx))
            )}
          </div>

          {/* Right Scroll Arrow */}
          {totalPages > 1 && (
            <button
              type="button"
              onClick={scrollRight}
              className="absolute right-2 top-1/2 -translate-y-1/2 z-20 w-8 h-8 rounded-full bg-white dark:bg-[#242526] border border-gray-200 dark:border-[#3e4042] flex items-center justify-center shadow-md text-gray-500 hover:text-[#8B0000] dark:hover:text-[#F8BF1E] transition-all opacity-0 group-hover/container:opacity-100"
              title="Scroll Right"
            >
              <ChevronRightIcon className="w-4 h-4 stroke-2" />
            </button>
          )}
        </div>
      </div>

      {totalPages > 1 && (
        <div className="flex justify-center items-center gap-2 mt-1 select-none">
          {Array.from({ length: totalPages }).map((_, pageIdx) => {
            const isActive = activeIndex === pageIdx;
            return (
              <button
                key={pageIdx}
                type="button"
                onClick={() => scrollToPage(pageIdx)}
                className={`transition-all duration-300 rounded-full cursor-pointer h-2 ${
                  isActive
                    ? "w-6 bg-[#8B0000] dark:bg-[#F8BF1E]"
                    : "w-2 bg-[#8B0000]/25 dark:bg-[#F8BF1E]/25 hover:bg-[#8B0000]/50 dark:hover:bg-[#F8BF1E]/50"
                }`}
                title={`Go to page ${pageIdx + 1}`}
              />
            );
          })}
        </div>
      )}
    </div>
  );
};

ManagementCarousel.propTypes = {
  title: PropTypes.string.isRequired,
  items: PropTypes.array.isRequired,
  loading: PropTypes.bool,
  emptyMessage: PropTypes.string,
  renderItem: PropTypes.func.isRequired,
  isDark: PropTypes.bool,
};
