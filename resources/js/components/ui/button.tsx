import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

// Pill buttons: ink for the main action, white for secondary, mint for the
// one invitation on a page. Press feedback is a quick scale, never a bounce.
const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-full text-sm font-medium transition-[color,background-color,border-color,box-shadow,transform] duration-150 ease-out active:scale-[0.97] disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 [&_svg]:shrink-0 outline-none focus-visible:ring-ring/60 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "bg-primary text-primary-foreground shadow-[0_1px_2px_oklch(0_0_0/0.12),inset_0_1px_0_oklch(1_0_0/0.08)] hover:bg-primary/88",
        brand:
          "bg-brand text-brand-foreground shadow-[inset_0_-1px_0_oklch(0_0_0/0.06)] hover:brightness-[0.97]",
        destructive:
          "bg-destructive text-white shadow-xs hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40",
        outline:
          "border border-border bg-card text-foreground shadow-[0_1px_2px_oklch(0_0_0/0.04)] hover:bg-accent",
        secondary:
          "bg-secondary text-secondary-foreground hover:bg-secondary/70",
        ghost: "text-muted-foreground hover:bg-accent hover:text-foreground",
        link: "rounded-md text-brand-ink underline-offset-4 hover:underline active:scale-100",
      },
      size: {
        default: "h-9 px-4 has-[>svg]:px-3.5",
        sm: "h-8 px-3 text-[13px] has-[>svg]:px-2.5",
        lg: "h-11 px-6 text-[15px] has-[>svg]:px-5",
        icon: "size-9",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Button({
  className,
  variant,
  size,
  asChild = false,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
  }) {
  const Comp = asChild ? Slot : "button"

  return (
    <Comp
      data-slot="button"
      className={cn(buttonVariants({ variant, size, className }))}
      {...props}
    />
  )
}

export { Button, buttonVariants }
