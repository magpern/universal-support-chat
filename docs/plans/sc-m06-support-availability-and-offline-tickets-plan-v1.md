# SC-M06 Support Availability and Offline Tickets — Implementation Plan v1

## 1. References

- Charter: `docs/milestones/sc-m06-support-availability-and-offline-tickets.md`
- Requirements: **R5**, **R7**; ADR-0006

## 2. Decisions

- Schedule, exceptions, Automatic/Online/Offline owned by Support Chat.
- Offline human request always inserts durable ticket before any channel notify.
- Channel notify/ensure is optional and off the critical path.

## 3. Work packages

1. Availability settings + evaluation  
2. Waiting queue Hub UX  
3. Offline truthful copy  
4. Optional adapter notify hook  
5. Tests with adapter absent  

## 4. Out of scope

Implementing Telegram `/support` in this plugin; SC-AI2.

## 5. Definition of done

R5/R7 charter acceptance; ticket without Telegram proven.
