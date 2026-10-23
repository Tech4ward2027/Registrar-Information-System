import React, { createContext, useContext, useState, useEffect } from 'react';
import { INITIAL_TICKETS } from '../constants/ticketConstants';
import { useAuth } from './AuthProvider';

const TicketContext = createContext(null);

const STORAGE_KEY = 'ris_tickets_data_v1';

export const TicketProvider = ({ children }) => {
  const { user } = useAuth();
  const [tickets, setTickets] = useState(() => {
    try {
      const saved = localStorage.getItem(STORAGE_KEY);
      if (saved) {
        return JSON.parse(saved);
      }
    } catch (e) {
      console.warn('Failed to parse cached tickets, reverting to defaults:', e);
    }
    return INITIAL_TICKETS;
  });

  useEffect(() => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(tickets));
    } catch (e) {
      console.error('Failed to save tickets to local storage:', e);
    }
  }, [tickets]);

  // Submit a new ticket
  const createTicket = ({ subject, category, priority = 'Normal', description, attachments = [] }) => {
    const year = new Date().getFullYear();
    const count = tickets.length + 1;
    const ticket_number = `TKT-${year}-${String(count).padStart(4, '0')}`;
    const now = new Date().toISOString();

    const creatorName = user?.name || user?.email?.split('@')[0] || 'Student Requestor';
    const studentNumber = user?.academic_record?.student_number || user?.undergrad_requestor_profile?.student_number || 'N/A';
    const userRole = user?.role_name || 'student';

    const newTicket = {
      id: `tkt-${Date.now()}`,
      ticket_number,
      subject: subject.trim(),
      category,
      priority,
      status: 'Open',
      created_at: now,
      updated_at: now,
      creator: {
        id: user?.user_id,
        name: creatorName,
        student_number: studentNumber,
        role: userRole,
        email: user?.email || 'user@pup.edu.ph',
      },
      active_handler: null,
      description: description.trim(),
      attachments: attachments.map(f => ({
        name: f.name || 'attachment',
        size: f.size ? `${(f.size / 1024).toFixed(0)} KB` : 'N/A',
        type: f.type || 'application/octet-stream',
      })),
      messages: [
        {
          id: `msg-${Date.now()}`,
          sender_type: 'student',
          sender_name: creatorName,
          content: description.trim(),
          timestamp: now,
          attachments: attachments.map(f => ({
            name: f.name || 'attachment',
            size: f.size ? `${(f.size / 1024).toFixed(0)} KB` : 'N/A',
          })),
        },
      ],
      activities: [
        {
          id: `act-${Date.now()}`,
          text: 'Ticket submitted with status Open',
          actor: creatorName,
          timestamp: now,
        },
      ],
      resolution_note: null,
    };

    setTickets(prev => [newTicket, ...prev]);
    return newTicket;
  };

  // Add message / reply to conversation
  const addMessage = (ticketId, content, isInternal = false) => {
    if (!content || !content.trim()) return;
    const now = new Date().toISOString();
    const isStaff = user?.role_name === 'admin' || user?.role_name === 'super_admin';
    const senderName = user?.name || user?.email || (isStaff ? 'Registrar Staff' : 'Student Requestor');

    setTickets(prev =>
      prev.map(tkt => {
        if (tkt.id !== ticketId && tkt.ticket_number !== ticketId) return tkt;

        const newMsg = {
          id: `msg-${Date.now()}`,
          sender_type: isStaff ? 'staff' : 'student',
          sender_name: senderName,
          content: content.trim(),
          timestamp: now,
          is_internal: isInternal,
        };

        const newAct = {
          id: `act-${Date.now()}`,
          text: isInternal ? 'Staff added an internal note' : `${isStaff ? 'Staff' : 'Student'} sent a reply`,
          actor: senderName,
          timestamp: now,
        };

        return {
          ...tkt,
          updated_at: now,
          messages: [...tkt.messages, newMsg],
          activities: [...tkt.activities, newAct],
        };
      })
    );
  };

  // Change ticket status (Policy rules enforced)
  const updateTicketStatus = (ticketId, newStatus, resolutionNote = null) => {
    const now = new Date().toISOString();
    const actorName = user?.name || user?.email || 'Registrar Staff';

    setTickets(prev =>
      prev.map(tkt => {
        if (tkt.id !== ticketId && tkt.ticket_number !== ticketId) return tkt;

        const activityText = resolutionNote
          ? `Status changed to ${newStatus} (Resolution Note: ${resolutionNote})`
          : `Status changed to ${newStatus}`;

        return {
          ...tkt,
          status: newStatus,
          updated_at: now,
          resolution_note: resolutionNote !== null ? resolutionNote : tkt.resolution_note,
          activities: [
            ...tkt.activities,
            {
              id: `act-${Date.now()}`,
              text: activityText,
              actor: actorName,
              timestamp: now,
            },
          ],
        };
      })
    );
  };

  // Shared ticket queue: set temporary handler indicator (Policy 6)
  const claimActiveHandling = (ticketId) => {
    const now = new Date().toISOString();
    const handlerName = user?.name || user?.email || 'Registrar Staff';

    setTickets(prev =>
      prev.map(tkt => {
        if (tkt.id !== ticketId && tkt.ticket_number !== ticketId) return tkt;
        return {
          ...tkt,
          active_handler: {
            id: user?.user_id || 'staff-current',
            name: handlerName,
            started_at: now,
          },
        };
      })
    );
  };

  // Release active handling indicator
  const releaseActiveHandling = (ticketId) => {
    setTickets(prev =>
      prev.map(tkt => {
        if (tkt.id !== ticketId && tkt.ticket_number !== ticketId) return tkt;
        return {
          ...tkt,
          active_handler: null,
        };
      })
    );
  };

  // Reopen resolved ticket
  const reopenTicket = (ticketId, reason = '') => {
    const now = new Date().toISOString();
    const actorName = user?.name || user?.email || 'Student Requestor';

    setTickets(prev =>
      prev.map(tkt => {
        if (tkt.id !== ticketId && tkt.ticket_number !== ticketId) return tkt;

        return {
          ...tkt,
          status: 'Open',
          updated_at: now,
          activities: [
            ...tkt.activities,
            {
              id: `act-${Date.now()}`,
              text: reason ? `Ticket reopened by student: "${reason}"` : 'Ticket reopened by student',
              actor: actorName,
              timestamp: now,
            },
          ],
        };
      })
    );
  };

  return (
    <TicketContext.Provider
      value={{
        tickets,
        createTicket,
        addMessage,
        updateTicketStatus,
        claimActiveHandling,
        releaseActiveHandling,
        reopenTicket,
      }}
    >
      {children}
    </TicketContext.Provider>
  );
};

export const useTickets = () => {
  const context = useContext(TicketContext);
  if (!context) {
    throw new Error('useTickets must be used within a TicketProvider');
  }
  return context;
};
