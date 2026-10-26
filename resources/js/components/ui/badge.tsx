import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

// Small pastel pills. Each tone is a soft fill with an ink of the same hue,
// edged by a faint ring of that ink so it holds up on white and on grey.
const badgeVariants = cva(
  "inline-flex items-center justify-center rounded-full px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1 [&>svg]:pointer-events-none focus-visible:ring-ring/50 focus-visible:ring-[3px] transition-[color,background-color,box-shadow] overflow-hidden",
  {
    variants: {
      variant: {
        default: "bg-primary text-primary-foreground [a&]:hover:bg-primary/90",
        secondary:
          "bg-secondary text-secondary-foreground [a&]:hover:bg-secondary/80",
        destructive: "bg-rose-soft text-rose ring-1 ring-rose/15 ring-inset",
        outline:
          "bg-card text-foreground ring-1 ring-border ring-inset [a&]:hover:bg-accent",
        mint: "bg-mint-soft text-mint ring-1 ring-mint/15 ring-inset",
        sky: "bg-sky-soft text-sky ring-1 ring-sky/15 ring-inset",
        rose: "bg-rose-soft text-rose ring-1 ring-rose/15 ring-inset",
        amber: "bg-amber-soft text-amber ring-1 ring-amber/15 ring-inset",
        violet: "bg-violet-soft text-violet ring-1 ring-violet/15 ring-inset",
        brand: "bg-brand text-brand-foreground",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Badge({
  className,
  variant,
  asChild = false,
  ...props
}: React.ComponentProps<"span"> &
  VariantProps<typeof badgeVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : "span"

  return (
    <Comp
      data-slot="badge"
      className={cn(badgeVariants({ variant }), className)}
      {...props}
    />
  )
}

export { Badge, badgeVariants }
